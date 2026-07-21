<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\GeneratedMockup;
use App\Models\MockupTemplate;
use App\Models\Poster;
use App\Models\PosterActivity;
use App\Services\DenoiseService;
use App\Services\DpiValidator;
use App\Services\ImageFinalizer;
use App\Services\MagickService;
use App\Services\MockupService;
use App\Services\NamingService;
use App\Services\QualityControlService;
use App\Services\UpscaleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class ProcessPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public function __construct(
        public array $posterIds,
        public array $config,
        public int $backgroundTaskId,
    ) {
        $this->queue = 'upscale';
    }

    public function handle(
        UpscaleService $upscaleService,
        NamingService $namingService,
        DpiValidator $dpiValidator,
        MockupService $mockupService,
        DenoiseService $denoiseService,
        QualityControlService $qcService,
        ImageFinalizer $finalizer,
        MagickService $magick,
    ): void {
        set_time_limit(0);

        $task = BackgroundTask::find($this->backgroundTaskId);
        if (! $task) {
            return;
        }

        $task->markRunning();

        $posters = Poster::whereIn('id', $this->posterIds)->get();
        $totalSteps = $this->countTotalSteps($posters);
        $currentStep = 0;

        // Stage 1: Upscale
        if ($this->config['upscale']['enabled']) {
            foreach ($posters as $poster) {
                // Skip already upscaled
                if ($poster->upscaled_path && file_exists($poster->upscaled_path)) {
                    $currentStep++;
                    $this->reportProgress($task, $currentStep, $totalSteps, "Skipped: {$poster->title} (already upscaled)");
                    continue;
                }

                $this->reportProgress($task, $currentStep, $totalSteps, "Upscaling: {$poster->title}");

                $this->runUpscale($poster, $upscaleService, $namingService, $dpiValidator, $denoiseService, $qcService, $finalizer);
                $poster->refresh();

                $currentStep++;
                $this->reportProgress($task, $currentStep, $totalSteps, "Upscaled: {$poster->title}");
            }
        }

        // Stage 2: Mockups
        if ($this->config['mockups']['enabled']) {
            $templates = $this->getTemplates();

            foreach ($posters as $poster) {
                foreach ($templates as $template) {
                    $this->reportProgress($task, $currentStep, $totalSteps, "Mockup: {$poster->title} + {$template->name}");

                    $this->runMockup($poster, $template, $mockupService, $namingService);

                    $currentStep++;
                    $this->reportProgress($task, $currentStep, $totalSteps, "Mockup done: {$poster->title}");
                }
            }
        }

        // Stage 3: Export (QC gate: failing posters are skipped, never silently)
        $blocked = [];
        if ($this->config['export']['enabled']) {
            foreach ($posters as $poster) {
                $gate = $qcService->gateForExport($poster);

                if ($gate->verdict === 'fail') {
                    $blocked[] = $poster->title;
                    PosterActivity::log($poster->id, 'export_blocked', [
                        'qc_report_id' => $gate->id,
                        'reasons' => $gate->reasons,
                    ]);
                    $currentStep++;
                    $this->reportProgress($task, $currentStep, $totalSteps, "GEBLOKKEERD (QC: niet printen): {$poster->title}");
                    continue;
                }

                // Exports komen uitsluitend van de behandelde upscale-master;
                // de onbewerkte bron mag nooit ongefilterd naar print.
                if (! $poster->upscaled_path || ! file_exists($poster->upscaled_path)) {
                    $blocked[] = $poster->title;
                    PosterActivity::log($poster->id, 'export_blocked', [
                        'reasons' => ['Geen upscale-master: draai eerst de upscale-stap; exporteren vanaf de onbewerkte bron is niet toegestaan.'],
                    ]);
                    $currentStep++;
                    $this->reportProgress($task, $currentStep, $totalSteps, "GEBLOKKEERD (geen upscale-master): {$poster->title}");
                    continue;
                }

                $this->reportProgress($task, $currentStep, $totalSteps, "Exporting: {$poster->title}");

                $this->runExport($poster, $namingService, $finalizer, $qcService);
                $poster->update(['status' => 'exported']);

                $currentStep++;
                $this->reportProgress($task, $currentStep, $totalSteps, "Exported: {$poster->title}");
            }
        }

        $stages = [];
        if ($this->config['upscale']['enabled']) $stages[] = 'upscale';
        if ($this->config['mockups']['enabled']) $stages[] = 'mockups';
        if ($this->config['export']['enabled']) $stages[] = 'export';

        foreach ($posters as $poster) {
            PosterActivity::log($poster->id, 'pipeline_completed', ['stages' => $stages]);
        }

        if ($blocked) {
            $task->updateProgress(
                'Klaar. Export geblokkeerd door QC voor: ' . implode(', ', $blocked),
                99,
            );
        }

        $task->markCompleted();
    }

    public function failed(\Throwable $e): void
    {
        // Clear progress caches so poster cards don't hang on a stale percentage.
        foreach ($this->posterIds as $posterId) {
            Cache::forget("upscale_progress_{$posterId}");
        }

        BackgroundTask::find($this->backgroundTaskId)
            ?->markFailed($e->getMessage());
    }

    private function countTotalSteps($posters): int
    {
        $steps = 0;

        if ($this->config['upscale']['enabled']) {
            $steps += $posters->count();
        }

        if ($this->config['mockups']['enabled']) {
            $templateCount = $this->countTemplates();
            $steps += $posters->count() * $templateCount;
        }

        if ($this->config['export']['enabled']) {
            $steps += $posters->count();
        }

        return max($steps, 1);
    }

    private function countTemplates(): int
    {
        $selection = $this->config['mockups']['templateSelection'];

        return match ($selection) {
            'all' => MockupTemplate::count(),
            'category' => MockupTemplate::where('category', $this->config['mockups']['category'])->count(),
            'specific' => count($this->config['mockups']['templateIds']),
            default => 0,
        };
    }

    private function getTemplates()
    {
        $selection = $this->config['mockups']['templateSelection'];

        return match ($selection) {
            'all' => MockupTemplate::all(),
            'category' => MockupTemplate::where('category', $this->config['mockups']['category'])->get(),
            'specific' => MockupTemplate::whereIn('id', $this->config['mockups']['templateIds'])->get(),
            default => collect(),
        };
    }

    private function reportProgress(BackgroundTask $task, int $current, int $total, string $stage): void
    {
        $percent = $total > 0 ? (int) ($current / $total * 100) : 0;
        $task->updateProgress($stage, min($percent, 99));
    }

    private function runUpscale(
        Poster $poster,
        UpscaleService $upscaleService,
        NamingService $namingService,
        DpiValidator $dpiValidator,
        DenoiseService $denoiseService,
        QualityControlService $qcService,
        ImageFinalizer $finalizer,
    ): void {
        $cfg = $this->config['upscale'];
        $denoiseCfg = $this->config['denoise'] ?? ['enabled' => false, 'strength' => 'normal'];

        // ── Automatische modus: formaat-gating + configuratie-keuze per afbeelding ──
        if ($cfg['auto'] ?? false) {
            $autoTune = app(\App\Services\AutoTuneService::class);

            $gate = $autoTune->gate($poster->original_path, $cfg['targetSize']);
            if (! $gate['feasible']) {
                // Eerlijk weigeren zonder de hele batch te laten stranden.
                PosterActivity::log($poster->id, 'upscale_geweigerd', [
                    'reden' => sprintf(
                        '%s cm niet haalbaar: effectief %d DPI (minimaal %d). Grootste haalbare formaat: %s.',
                        $cfg['targetSize'],
                        $gate['effective_dpi'],
                        $gate['min_dpi'],
                        $gate['max_sellable_size'] ? $gate['max_sellable_size'] . ' cm' : 'geen',
                    ),
                ]);
                Cache::forget("upscale_progress_{$poster->id}");
                return;
            }

            Cache::put("upscale_progress_{$poster->id}", [
                'stage' => 'autotune',
                'percent' => 3,
            ], now()->addMinutes(30));

            $choice = $autoTune->choose($poster->original_path, $cfg['targetSize'], $cfg['targetDpi']);
            $chosen = $choice['config'];

            $cfg['model'] = $chosen['model'];
            $cfg['denoise'] = (int) $chosen['blend_bicubic'];
            $cfg['sharpen'] = (int) $chosen['sharpen'];
            $denoiseCfg = [
                'enabled' => $chosen['pre_denoise'] !== 'off',
                'strength' => $chosen['pre_denoise'] !== 'off' ? $chosen['pre_denoise'] : $denoiseCfg['strength'],
            ];

            PosterActivity::log($poster->id, 'autotuned', [
                'chosen' => $chosen,
                'score' => $choice['score'],
                'candidates' => $choice['candidates'],
            ]);
        }

        $outputFilename = $namingService->upscaledName($poster->slug);
        $outputPath = storage_path('app/upscaled/' . $outputFilename);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $targetPixels = $dpiValidator->pixelsAtDpi($cfg['targetSize'], $cfg['targetDpi']);

        if (! $targetPixels) {
            throw new \RuntimeException("Unknown print size: {$cfg['targetSize']}");
        }

        $colorAdjust = [];
        if (($cfg['brightness'] ?? 100) !== 100 || ($cfg['contrast'] ?? 0) !== 0 || ($cfg['saturation'] ?? 100) !== 100) {
            $colorAdjust = [
                'brightness' => $cfg['brightness'] ?? 100,
                'contrast' => $cfg['contrast'] ?? 0,
                'saturation' => $cfg['saturation'] ?? 100,
            ];
        }

        // Update cache progress for compatibility with UpscaleQueue page
        Cache::put("upscale_progress_{$poster->id}", [
            'stage' => 'qc-bron',
            'percent' => 5,
        ], now()->addMinutes(30));

        // ── QC on the untouched source ──
        $sourceMetrics = $qcService->analyze($poster->original_path);
        $qcService->runAndStore($poster->original_path, 'source', $poster->id, metrics: $sourceMetrics);

        // ── Denoise before upscaling; the source file is never modified ──
        $upscaleInput = $poster->original_path;

        if ($denoiseCfg['enabled']) {
            Cache::put("upscale_progress_{$poster->id}", [
                'stage' => 'denoise',
                'percent' => 15,
            ], now()->addMinutes(30));

            $denoisedPath = storage_path('app/denoised/' . $poster->slug . '_denoised.png');
            $denoisedDir = dirname($denoisedPath);
            if (! is_dir($denoisedDir)) {
                mkdir($denoisedDir, 0755, true);
            }

            $denoiseService->denoise($poster->original_path, $denoisedPath, $denoiseCfg['strength']);

            $denoisedMetrics = $qcService->analyze($denoisedPath);
            $comparison = $qcService->compare($sourceMetrics, $denoisedMetrics);

            $compareImagePath = null;
            if ($sourceMetrics['flattest_block']) {
                $compareImagePath = storage_path('app/qc/' . $poster->slug . '_denoise_compare.png');
                $qcService->comparisonCrop($poster->original_path, $denoisedPath, $sourceMetrics['flattest_block'], $compareImagePath);
            }

            $qcService->runAndStore(
                $denoisedPath,
                'denoised',
                $poster->id,
                comparison: $comparison,
                comparisonImagePath: $compareImagePath,
                metrics: $denoisedMetrics,
            );

            PosterActivity::log($poster->id, 'denoised', [
                'strength' => $denoiseCfg['strength'],
                'noise_before' => $comparison['noise_before'],
                'noise_after' => $comparison['noise_after'],
                'detail_loss_percent' => $comparison['detail_loss_percent'],
            ]);

            $upscaleInput = $denoisedPath;
        }

        Cache::put("upscale_progress_{$poster->id}", [
            'stage' => 'upscaling',
            'percent' => 30,
        ], now()->addMinutes(30));

        $upscaleService->smartUpscale(
            $upscaleInput,
            $outputPath,
            $targetPixels['width'],
            $targetPixels['height'],
            $cfg['model'],
            $cfg['denoise'],
            $cfg['sharpen'],
            $colorAdjust,
            $cfg['tileSize'] ?? 0,
            $cfg['targetDpi'],
        );

        // ── Embed ICC profile + true DPI, then final QC ──
        $finalizer->finalize($outputPath, $cfg['targetDpi']);
        $qcService->runAndStore($outputPath, 'output', $poster->id, requirePrintReady: true);

        $poster->update([
            'upscaled_path' => $outputPath,
            'status' => 'upscaled',
        ]);

        Cache::put("upscale_progress_{$poster->id}", [
            'stage' => 'completed',
            'percent' => 100,
        ], now()->addMinutes(30));
    }

    private function runMockup(Poster $poster, MockupTemplate $template, MockupService $mockupService, NamingService $namingService): void
    {
        $cfg = $this->config['mockups'];
        $slots = $template->getAllSlots();

        // Only handle single-slot templates in pipeline
        if (count($slots) < 1) {
            return;
        }

        $ext = $cfg['outputFormat'] === 'png' ? 'png' : 'jpg';
        $outputFilename = $namingService->mockupName($poster->slug, $template->slug);
        $outputFilename = preg_replace('/\.\w+$/', ".{$ext}", $outputFilename);
        $outputPath = storage_path('app/mockups/' . $outputFilename);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $posterPath = $poster->upscaled_path ?? $poster->original_path;

        $mockupService->generate(
            posterPath: $posterPath,
            backgroundPath: $template->background_path,
            corners: $slots[0]['corners'],
            outputPath: $outputPath,
            options: [
                'shadowPath' => $template->shadow_path,
                'framePath' => $template->frame_path,
                'brightness' => $template->brightness_adjust,
                'fitMode' => $cfg['fitMode'],
                'format' => $cfg['outputFormat'],
                'quality' => $cfg['outputQuality'],
                'framePreset' => $cfg['framePreset'],
            ],
        );

        GeneratedMockup::create([
            'poster_id' => $poster->id,
            'template_id' => $template->id,
            'output_path' => $outputPath,
        ]);

        if ($poster->status !== 'exported') {
            $poster->update(['status' => 'mockups_ready']);
        }
    }

    private function runExport(Poster $poster, NamingService $namingService, ImageFinalizer $finalizer, QualityControlService $qcService): void
    {
        $cfg = $this->config['export'];
        $outputDir = $cfg['outputDir'];

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $sourcePath = $poster->upscaled_path;
        $dpiValidator = new DpiValidator();

        foreach ($cfg['sizes'] as $sizeName) {
            $pixels = $dpiValidator->pixelsAt300Dpi($sizeName);
            if (! $pixels) {
                continue;
            }

            // Print exports are always PNG — no JPEG in the print chain.
            $filename = preg_replace('/\.\w+$/', '.png', $namingService->sizeVariantName($poster->slug, $sizeName));
            $outputPath = rtrim($outputDir, '/\\') . '/' . $filename;

            $finalizer->exportPrintFile($sourcePath, $outputPath, $pixels['width'], $pixels['height'], 300);

            // Harde poort: volledige QC (incl. ruisdrempel) op elke
            // eind-export — een te ruizig of niet-print-klaar bestand
            // laat de pipeline expliciet falen.
            $report = $qcService->runAndStore($outputPath, 'export', $poster->id, requirePrintReady: true);
            if ($report->verdict === 'fail') {
                throw new \RuntimeException(
                    "Export {$sizeName} niet print-klaar: " . implode(' ', $report->reasons)
                );
            }
        }
    }
}
