<?php

namespace App\Jobs;

use App\Models\Poster;
use App\Services\NamingService;
use App\Services\UpscaleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpscaleImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Poster $poster,
        public int $scale = 4,
        public string $model = 'realesrgan-x4plus',
    ) {}

    public function handle(UpscaleService $upscaleService, NamingService $namingService): void
    {
        $outputFilename = $namingService->upscaledName($this->poster->slug);
        $outputPath = storage_path('app/upscaled/' . $outputFilename);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $upscaleService->upscale(
            $this->poster->original_path,
            $outputPath,
            $this->scale,
            $this->model,
        );

        $this->poster->update([
            'upscaled_path' => $outputPath,
            'status' => 'upscaled',
        ]);
    }
}
