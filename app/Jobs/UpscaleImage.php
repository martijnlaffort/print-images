<?php

namespace App\Jobs;

use App\Models\Poster;
use App\Services\DpiValidator;
use App\Services\NamingService;
use App\Services\UpscaleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class UpscaleImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public Poster $poster,
        public string $targetSize = '50x70',
        public int $targetDpi = 300,
        public string $model = 'realesrgan-x4plus',
        public int $denoise = 50,
        public int $sharpen = 0,
        public array $colorAdjust = [],
        public int $tileSize = 0,
    ) {}

    public function handle(UpscaleService $upscaleService, NamingService $namingService, DpiValidator $dpiValidator): void
    {
        $this->updateProgress('upscaling', 10);

        $outputFilename = $namingService->upscaledName($this->poster->slug);
        $outputPath = storage_path('app/upscaled/' . $outputFilename);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $targetPixels = $dpiValidator->pixelsAtDpi($this->targetSize, $this->targetDpi);

        if (! $targetPixels) {
            throw new \RuntimeException("Unknown print size: {$this->targetSize}");
        }

        $this->updateProgress('upscaling', 30);

        $upscaleService->smartUpscale(
            $this->poster->original_path,
            $outputPath,
            $targetPixels['width'],
            $targetPixels['height'],
            $this->model,
            $this->denoise,
            $this->sharpen,
            $this->colorAdjust,
            $this->tileSize,
            onProgress: function (int $percent) {
                // Map 0-100% from smartUpscale to 30-90% overall
                $mapped = 30 + (int) ($percent * 0.6);
                $this->updateProgress('upscaling', $mapped);
            },
        );

        $this->updateProgress('finalizing', 90);

        $this->poster->update([
            'upscaled_path' => $outputPath,
            'status' => 'upscaled',
        ]);

        $this->updateProgress('completed', 100);
    }

    private function updateProgress(string $stage, int $percent): void
    {
        Cache::put("upscale_progress_{$this->poster->id}", [
            'stage' => $stage,
            'percent' => $percent,
        ], now()->addMinutes(30));
    }
}
