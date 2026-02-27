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
use Imagick;

class GenerateSizeVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Poster $poster,
        public array $sizes,
        public string $outputDir,
        public string $namingPattern = '{title}_{size}.png',
    ) {}

    public function handle(NamingService $namingService): void
    {
        if (! is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $sourcePath = $this->poster->upscaled_path ?? $this->poster->original_path;

        foreach ($this->sizes as $sizeName) {
            $pixels = DpiValidator::PIXELS_AT_300DPI[$sizeName] ?? null;
            if (! $pixels) {
                continue;
            }

            $image = new Imagick($sourcePath);
            $image->resizeImage(
                $pixels['width'],
                $pixels['height'],
                Imagick::FILTER_LANCZOS,
                1,
                true
            );

            $image->setImageResolution(300, 300);
            $image->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);

            $filename = $namingService->sizeVariantName($this->poster->slug, $sizeName);
            $outputPath = rtrim($this->outputDir, '/\\') . '/' . $filename;

            $image->writeImage($outputPath);
            $image->destroy();
        }
    }
}
