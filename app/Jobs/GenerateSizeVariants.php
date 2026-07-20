<?php

namespace App\Jobs;

use App\Models\Poster;
use App\Models\PosterActivity;
use App\Services\DpiValidator;
use App\Services\ImageFinalizer;
use App\Services\MagickService;
use App\Services\NamingService;
use App\Services\QualityControlService;
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

    public int $timeout = 600;

    public function __construct(
        public Poster $poster,
        public array $sizes,
        public string $outputDir,
        public string $namingPattern = '{title}_{size}.png',
        public ?int $backgroundTaskId = null,
    ) {
        $this->queue = 'export';
    }

    public function handle(
        NamingService $namingService,
        MagickService $magick,
        ImageFinalizer $finalizer,
        QualityControlService $qcService,
    ): void {
        if (! is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        // QC gate: never export a failing poster silently.
        $gate = $qcService->gateForExport($this->poster);
        if ($gate->verdict === 'fail') {
            PosterActivity::log($this->poster->id, 'export_blocked', [
                'qc_report_id' => $gate->id,
                'reasons' => $gate->reasons,
            ]);

            if ($this->backgroundTaskId) {
                \App\Models\BackgroundTask::find($this->backgroundTaskId)
                    ?->markFailed("Export geblokkeerd door QC (NIET PRINTEN): {$this->poster->title}");
            }

            return;
        }

        $sourcePath = $this->poster->upscaled_path ?? $this->poster->original_path;

        $dpiValidator = new DpiValidator();

        foreach ($this->sizes as $sizeName) {
            $pixels = $dpiValidator->pixelsAt300Dpi($sizeName);
            if (! $pixels) {
                continue;
            }

            // Print exports are always PNG — no JPEG in the print chain.
            $filename = preg_replace('/\.\w+$/', '.png', $namingService->sizeVariantName($this->poster->slug, $sizeName));
            $outputPath = rtrim($this->outputDir, '/\\') . '/' . $filename;

            $result = Process::timeout(120)->run([
                $magick->path(),
                $sourcePath,
                '-filter', 'Lanczos',
                '-resize', "{$pixels['width']}x{$pixels['height']}",
                '-density', '300',
                '-units', 'PixelsPerInch',
                ...$finalizer->profileArgs(),
                $outputPath,
            ]);

            if ($result->failed()) {
                throw new RuntimeException(
                    "Failed to generate size variant {$sizeName}: " . $result->errorOutput()
                );
            }
        }

        PosterActivity::log($this->poster->id, 'exported', [
            'sizes' => $this->sizes,
        ]);

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
