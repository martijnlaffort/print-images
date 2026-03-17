<?php

namespace App\Livewire;

use App\Jobs\ProcessPipeline;
use App\Models\BackgroundTask;
use App\Models\MockupTemplate;
use App\Models\Poster;
use App\Models\Setting;
use App\Services\DpiValidator;
use App\Services\UpscaleService;
use Livewire\Component;

class QuickPipeline extends Component
{
    // Poster selection
    public array $selectedPosters = [];
    public bool $selectAll = true;

    // Upscale
    public bool $enableUpscale = true;
    public string $upscalePreset = 'standard';
    public string $targetSize = '50x70';
    public int $targetDpi = 300;
    public string $model = 'realesrgan-x4plus';
    public int $denoise = 50;
    public int $sharpen = 0;
    public int $brightness = 100;
    public int $contrast = 0;
    public int $saturation = 100;
    public int $tileSize = 0;

    // Mockups
    public bool $enableMockups = true;
    public string $templateSelection = 'all';
    public string $categoryFilter = '';
    public array $selectedTemplates = [];
    public string $fitMode = 'fill';
    public string $mockupFormat = 'jpg';
    public int $mockupQuality = 92;
    public string $framePreset = 'none';

    // Export
    public bool $enableExport = true;
    public array $exportSizes = ['50x70'];
    public string $exportFormat = 'png';
    public int $exportQuality = 92;
    public string $outputDir = '';

    // Processing state
    public bool $processing = false;
    public ?int $pipelineTaskId = null;

    public function mount(): void
    {
        $this->outputDir = Setting::get('export.default_dir', storage_path('app/exports'));

        // Select all posters by default
        $this->selectedPosters = Poster::pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    public function applyPreset(string $preset): void
    {
        match ($preset) {
            'standard' => $this->setPreset(50, 0, 100, 100, 0),
            'detailed' => $this->setPreset(70, 20, 100, 100, 0),
            'sharp' => $this->setPreset(20, 40, 100, 100, 0),
            'vivid' => $this->setPreset(50, 10, 110, 110, 0),
            'gentle' => $this->setPreset(80, 0, 100, 100, 0),
            default => null,
        };

        $this->upscalePreset = $preset;
        $this->dispatch('toast', type: 'info', message: ucfirst($preset) . ' preset applied.');
    }

    private function setPreset(int $denoise, int $sharpen, int $brightness, int $saturation, int $contrast): void
    {
        $this->denoise = $denoise;
        $this->sharpen = $sharpen;
        $this->brightness = $brightness;
        $this->saturation = $saturation;
        $this->contrast = $contrast;
    }

    public function updatedSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedPosters = Poster::pluck('id')
                ->map(fn ($id) => (string) $id)
                ->toArray();
        } else {
            $this->selectedPosters = [];
        }
    }

    public function selectOutputDir(): void
    {
        try {
            $dir = \Native\Laravel\Dialog::new()
                ->title('Select export folder')
                ->folders()
                ->open();

            if ($dir) {
                $this->outputDir = $dir;
            }
        } catch (\Throwable $e) {
            logger()->error('Dialog error: ' . $e->getMessage());
        }
    }

    public function startPipeline(): void
    {
        if (empty($this->selectedPosters)) {
            $this->dispatch('toast', type: 'error', message: 'Select at least one poster.');
            return;
        }

        if (! $this->enableUpscale && ! $this->enableMockups && ! $this->enableExport) {
            $this->dispatch('toast', type: 'error', message: 'Enable at least one stage.');
            return;
        }

        if ($this->enableMockups && $this->templateSelection === 'specific' && empty($this->selectedTemplates)) {
            $this->dispatch('toast', type: 'error', message: 'Select at least one template.');
            return;
        }

        if ($this->enableExport && empty($this->exportSizes)) {
            $this->dispatch('toast', type: 'error', message: 'Select at least one export size.');
            return;
        }

        $posterIds = array_map('intval', $this->selectedPosters);
        $posterCount = count($posterIds);

        // Build stage summary
        $stages = [];
        if ($this->enableUpscale) {
            $stages[] = 'upscale';
        }
        if ($this->enableMockups) {
            $stages[] = 'mockups';
        }
        if ($this->enableExport) {
            $stages[] = 'export';
        }

        $task = BackgroundTask::create([
            'type' => 'pipeline',
            'name' => "Pipeline: {$posterCount} posters (" . implode(' + ', $stages) . ')',
            'status' => 'pending',
            'total_items' => 1,
        ]);

        $config = [
            'upscale' => [
                'enabled' => $this->enableUpscale,
                'targetSize' => $this->targetSize,
                'targetDpi' => $this->targetDpi,
                'model' => $this->model,
                'denoise' => $this->denoise,
                'sharpen' => $this->sharpen,
                'brightness' => $this->brightness,
                'contrast' => $this->contrast,
                'saturation' => $this->saturation,
                'tileSize' => $this->tileSize,
            ],
            'mockups' => [
                'enabled' => $this->enableMockups,
                'templateSelection' => $this->templateSelection,
                'category' => $this->categoryFilter,
                'templateIds' => array_map('intval', $this->selectedTemplates),
                'fitMode' => $this->fitMode,
                'outputFormat' => $this->mockupFormat,
                'outputQuality' => $this->mockupQuality,
                'framePreset' => $this->framePreset,
            ],
            'export' => [
                'enabled' => $this->enableExport,
                'sizes' => $this->exportSizes,
                'format' => $this->exportFormat,
                'quality' => $this->exportQuality,
                'outputDir' => $this->outputDir ?: storage_path('app/exports'),
                'namingPattern' => Setting::get('naming.size_variant', config('posterforge.naming.size_variant', '{title}_{size}.png')),
            ],
        ];

        ProcessPipeline::dispatch($posterIds, $config, $task->id);

        $this->processing = true;
        $this->pipelineTaskId = $task->id;
        $this->dispatch('toast', type: 'info', message: "Pipeline started for {$posterCount} poster(s).");
    }

    public function checkPipelineStatus(): void
    {
        if (! $this->pipelineTaskId) {
            return;
        }

        $task = BackgroundTask::find($this->pipelineTaskId);

        if (! $task || in_array($task->status, ['completed', 'failed'])) {
            $this->processing = false;

            if ($task?->status === 'failed') {
                $this->dispatch('toast', type: 'error', message: 'Pipeline failed: ' . ($task->error_message ?? 'Unknown error'));
            } else {
                $this->dispatch('toast', type: 'success', message: 'Pipeline completed successfully!');
            }

            $this->pipelineTaskId = null;
        }
    }

    public function getPipelineProgressProperty(): ?array
    {
        if (! $this->pipelineTaskId) {
            return null;
        }

        $task = BackgroundTask::find($this->pipelineTaskId);
        if (! $task) {
            return null;
        }

        return [
            'status' => $task->status,
            'progress' => $task->progress,
            'stage' => $task->stage,
        ];
    }

    public function getBinaryAvailableProperty(): bool
    {
        return app(UpscaleService::class)->isAvailable();
    }

    public function getPostersProperty()
    {
        return Poster::orderByDesc('created_at')->get();
    }

    public function getTemplatesProperty()
    {
        return MockupTemplate::orderBy('name')->get();
    }

    public function getCategoriesProperty(): array
    {
        return MockupTemplate::distinct()->pluck('category')->filter()->toArray();
    }

    public function getUpscaledCountProperty(): int
    {
        return Poster::whereIn('id', array_map('intval', $this->selectedPosters))
            ->whereNotNull('upscaled_path')
            ->count();
    }

    public function render()
    {
        $validator = app(DpiValidator::class);

        return view('livewire.quick-pipeline', [
            'posters' => $this->posters,
            'templates' => $this->templates,
            'categories' => $this->categories,
            'printSizes' => $validator->allSizes(),
            'models' => config('posterforge.upscale.models'),
            'pipelineProgress' => $this->pipelineProgress,
        ]);
    }
}
