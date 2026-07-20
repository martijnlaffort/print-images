<?php

namespace App\Jobs;

use App\Models\Poster;
use App\Models\PosterActivity;
use App\Services\DenoiseService;
use App\Services\DpiValidator;
use App\Services\ImageFinalizer;
use App\Services\NamingService;
use App\Services\QualityControlService;
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

    public int $timeout = 1800;

    public function __construct(
        public Poster $poster,
        public string $targetSize = '70x100',
        public int $targetDpi = 300,
        public string $model = 'realesrgan-x4plus',
        public int $denoise = 50,
        public int $sharpen = 0,
        public array $colorAdjust = [],
        public int $tileSize = 0,
        public ?int $backgroundTaskId = null,
        public bool $preDenoise = true,
        public string $preDenoiseStrength = 'normal',
    ) {
        $this->queue = 'upscale';
    }

    public function handle(
        UpscaleService $upscaleService,
        NamingService $namingService,
        DpiValidator $dpiValidator,
        DenoiseService $denoiseService,
        QualityControlService $qcService,
        ImageFinalizer $finalizer,
    ): void {
        set_time_limit(0);

        $bgTask = $this->backgroundTaskId
            ? \App\Models\BackgroundTask::find($this->backgroundTaskId)
            : null;

        $bgTask?->markRunning();

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

        // ── QC on the untouched source ──
        $this->progress($bgTask, 'qc-bron', 5);
        $sourceMetrics = $qcService->analyze($this->poster->original_path);
        $qcService->runAndStore(
            $this->poster->original_path,
            'source',
            $this->poster->id,
            metrics: $sourceMetrics,
        );

        // ── Denoise before any upscaling; source file is never touched ──
        $upscaleInput = $this->poster->original_path;

        if ($this->preDenoise) {
            $this->progress($bgTask, 'denoise', 15);

            $denoisedPath = storage_path('app/denoised/' . $this->poster->slug . '_denoised.png');
            $denoisedDir = dirname($denoisedPath);
            if (! is_dir($denoisedDir)) {
                mkdir($denoisedDir, 0755, true);
            }

            $denoiseService->denoise($this->poster->original_path, $denoisedPath, $this->preDenoiseStrength);

            $this->progress($bgTask, 'qc-na-denoise', 25);
            $denoisedMetrics = $qcService->analyze($denoisedPath);
            $comparison = $qcService->compare($sourceMetrics, $denoisedMetrics);

            $compareImagePath = null;
            if ($sourceMetrics['flattest_block']) {
                $compareImagePath = storage_path('app/qc/' . $this->poster->slug . '_denoise_compare.png');
                $qcService->comparisonCrop(
                    $this->poster->original_path,
                    $denoisedPath,
                    $sourceMetrics['flattest_block'],
                    $compareImagePath,
                );
            }

            $qcService->runAndStore(
                $denoisedPath,
                'denoised',
                $this->poster->id,
                comparison: $comparison,
                comparisonImagePath: $compareImagePath,
                metrics: $denoisedMetrics,
            );

            PosterActivity::log($this->poster->id, 'denoised', [
                'strength' => $this->preDenoiseStrength,
                'noise_before' => $comparison['noise_before'],
                'noise_after' => $comparison['noise_after'],
                'detail_loss_percent' => $comparison['detail_loss_percent'],
            ]);

            $upscaleInput = $denoisedPath;
        }

        $this->progress($bgTask, 'upscaling', 30);

        $upscaleService->smartUpscale(
            $upscaleInput,
            $outputPath,
            $targetPixels['width'],
            $targetPixels['height'],
            $this->model,
            $this->denoise,
            $this->sharpen,
            $this->colorAdjust,
            $this->tileSize,
            $this->targetDpi,
            onProgress: function (int $percent) use ($bgTask) {
                // Map 0-100% from smartUpscale to 30-80% overall
                $mapped = 30 + (int) ($percent * 0.5);
                $this->progress($bgTask, 'upscaling', $mapped);
            },
        );

        // ── Embed ICC profile + true DPI on the output ──
        $this->progress($bgTask, 'icc-dpi', 82);
        $finalizer->finalize($outputPath, $this->targetDpi);

        // ── Final QC on the print file (profile now required) ──
        $this->progress($bgTask, 'qc-output', 88);
        $outputReport = $qcService->runAndStore($outputPath, 'output', $this->poster->id, requireIcc: true);

        $this->poster->update([
            'upscaled_path' => $outputPath,
            'status' => 'upscaled',
        ]);

        PosterActivity::log($this->poster->id, 'upscaled', [
            'size' => $this->targetSize,
            'dpi' => $this->targetDpi,
            'model' => $this->model,
            'denoise' => $this->denoise,
            'pre_denoise' => $this->preDenoise ? $this->preDenoiseStrength : 'off',
            'qc_verdict' => $outputReport->verdict,
        ]);

        $this->progress($bgTask, 'completed', 100);
        $bgTask?->markCompleted();
    }

    public function failed(\Throwable $e): void
    {
        // Clear the progress cache so the poster card doesn't hang on a stale percentage.
        Cache::forget("upscale_progress_{$this->poster->id}");

        if ($this->backgroundTaskId) {
            \App\Models\BackgroundTask::find($this->backgroundTaskId)
                ?->markFailed($e->getMessage());
        }
    }

    private function progress(?\App\Models\BackgroundTask $bgTask, string $stage, int $percent): void
    {
        Cache::put("upscale_progress_{$this->poster->id}", [
            'stage' => $stage,
            'percent' => $percent,
        ], now()->addMinutes(30));

        $bgTask?->updateProgress($stage, $percent);
    }
}
