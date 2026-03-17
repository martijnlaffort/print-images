<?php

namespace App\Jobs;

use App\Models\Poster;
use App\Services\DpiValidator;
use App\Services\NamingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class GenerateSizeVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'export';

    public int $timeout = 300;

    public function __construct(
        public Poster $poster,
        public array $sizes,
        public string $outputDir,
        public string $namingPattern = '{title}_{size}.png',
        public ?int $backgroundTaskId = null,
    ) {}

    private function getMagickPath(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'C:\\Program Files\\ImageMagick-7.1.2-Q16\\magick.exe',
            default => 'magick',
        };
    }

    public function handle(NamingService $namingService): void
    {
        if (! is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $magick = $this->getMagickPath();
        $sourcePath = $this->poster->upscaled_path ?? $this->poster->original_path;

        $dpiValidator = new DpiValidator();

        foreach ($this->sizes as $sizeName) {
            $pixels = $dpiValidator->pixelsAt300Dpi($sizeName);
            if (! $pixels) {
                continue;
            }

            $filename = $namingService->sizeVariantName($this->poster->slug, $sizeName);
            $outputPath = rtrim($this->outputDir, '/\\') . '/' . $filename;

            $result = Process::timeout(120)->run([
                $magick,
                $sourcePath,
                '-filter', 'Lanczos',
                '-resize', "{$pixels['width']}x{$pixels['height']}",
                '-density', '300',
                '-units', 'PixelsPerInch',
                $outputPath,
            ]);

            if ($result->failed()) {
                throw new RuntimeException(
                    "Failed to generate size variant {$sizeName}: " . $result->errorOutput()
                );
            }
        }

        if ($this->backgroundTaskId) {
            \App\Models\BackgroundTask::find($this->backgroundTaskId)
                ?->incrementCompleted();
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($this->backgroundTaskId) {
            \App\Models\BackgroundTask::find($this->backgroundTaskId)
                ?->markFailed($e->getMessage());
        }
    }
}
