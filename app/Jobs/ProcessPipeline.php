<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\GeneratedMockup;
use App\Models\MockupTemplate;
use App\Models\Poster;
use App\Models\PosterActivity;
use App\Services\DpiValidator;
use App\Services\MockupService;
use App\Services\NamingService;
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

                $this->runUpscale($poster, $upscaleService, $namingService, $dpiValidator);
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

        // Stage 3: Export
        if ($this->config['export']['enabled']) {
            foreach ($posters as $poster) {
                $this->reportProgress($task, $currentStep, $totalSteps, "Exporting: {$poster->title}");

                $this->runExport($poster, $namingService);
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

        $task->markCompleted();
    }

    public function failed(\Throwable $e): void
    {
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

    private function runUpscale(Poster $poster, UpscaleService $upscaleService, NamingService $namingService, DpiValidator $dpiValidator): void
    {
        $cfg = $this->config['upscale'];

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
            'stage' => 'upscaling',
            'percent' => 10,
        ], now()->addMinutes(30));

        $upscaleService->smartUpscale(
            $poster->original_path,
            $outputPath,
            $targetPixels['width'],
            $targetPixels['height'],
            $cfg['model'],
            $cfg['denoise'],
            $cfg['sharpen'],
            $colorAdjust,
            $cfg['tileSize'] ?? 0,
        );

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

    private function runExport(Poster $poster, NamingService $namingService): void
    {
        $cfg = $this->config['export'];
        $outputDir = $cfg['outputDir'];

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $magick = match (PHP_OS_FAMILY) {
            'Windows' => 'C:\\Program Files\\ImageMagick-7.1.2-Q16\\magick.exe',
            default => 'magick',
        };

        $sourcePath = $poster->upscaled_path ?? $poster->original_path;
        $dpiValidator = new DpiValidator();

        $ext = $cfg['format'] === 'jpg' ? 'jpg' : 'png';
        $pattern = preg_replace('/\.\w+$/', ".{$ext}", $cfg['namingPattern']);

        foreach ($cfg['sizes'] as $sizeName) {
            $pixels = $dpiValidator->pixelsAt300Dpi($sizeName);
            if (! $pixels) {
                continue;
            }

            $filename = $namingService->sizeVariantName($poster->slug, $sizeName);
            $outputPath = rtrim($outputDir, '/\\') . '/' . $filename;

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
                throw new \RuntimeException(
                    "Failed to export {$sizeName} for {$poster->title}: " . $result->errorOutput()
                );
            }
        }
    }
}
