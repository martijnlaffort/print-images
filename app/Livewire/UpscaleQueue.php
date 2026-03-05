<?php

namespace App\Livewire;

use App\Jobs\UpscaleImage;
use App\Models\Poster;
use App\Services\DpiValidator;
use App\Services\UpscaleService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class UpscaleQueue extends Component
{
    public string $targetSize = '50x70';
    public int $targetDpi = 300;
    public string $model = 'realesrgan-x4plus';
    public int $denoise = 50;
    public int $sharpen = 0;
    public int $tileSize = 0;
    public int $brightness = 100;
    public int $contrast = 0;
    public int $saturation = 100;
    public array $selected = [];
    public bool $selectAll = false;
    public bool $processing = false;
    public ?string $processingStartedAt = null;
    public ?int $comparePoster = null;

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

    public function startUpscale(): void
    {
        $posters = Poster::whereIn('id', $this->selected)->get();
        $count = $posters->count();

        $colorAdjust = $this->getColorAdjust();

        foreach ($posters as $poster) {
            UpscaleImage::dispatch(
                $poster,
                $this->targetSize,
                $this->targetDpi,
                $this->model,
                $this->denoise,
                $this->sharpen,
                $colorAdjust,
                $this->tileSize,
            );
        }

        $this->processing = true;
        $this->processingStartedAt = now()->toDateTimeString();
        $this->selected = [];
        $this->selectAll = false;
        $this->dispatch('toast', type: 'info', message: "Upscale queued for {$count} poster(s).");
    }

    public function upscaleSingle(int $id): void
    {
        $poster = Poster::findOrFail($id);
        $colorAdjust = $this->getColorAdjust();

        UpscaleImage::dispatch(
            $poster,
            $this->targetSize,
            $this->targetDpi,
            $this->model,
            $this->denoise,
            $this->sharpen,
            $colorAdjust,
            $this->tileSize,
        );
        $this->processing = true;
        $this->processingStartedAt = $this->processingStartedAt ?? now()->toDateTimeString();
        $this->dispatch('toast', type: 'info', message: 'Upscale job queued.');
    }

    public function toggleCompare(int $id): void
    {
        $this->comparePoster = $this->comparePoster === $id ? null : $id;
    }

    public function checkJobStatus(): void
    {
        $pending = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('payload', 'like', '%UpscaleImage%')
            ->count();

        $failed = \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->where('payload', 'like', '%UpscaleImage%')
            ->when($this->processingStartedAt, fn ($q) => $q->where('failed_at', '>=', $this->processingStartedAt))
            ->count();

        if ($pending === 0) {
            $this->processing = false;
            $this->processingStartedAt = null;

            if ($failed > 0) {
                $this->dispatch('toast', type: 'error', message: "{$failed} upscale job(s) failed.");
            } else {
                $this->dispatch('toast', type: 'success', message: 'All upscale jobs completed.');
            }
        }
    }

    public function updatedSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selected = Poster::pluck('id')
                ->map(fn ($id) => (string) $id)
                ->toArray();
        } else {
            $this->selected = [];
        }
    }

    public function getBinaryAvailableProperty(): bool
    {
        return app(UpscaleService::class)->isAvailable();
    }

    public function getPostersProperty()
    {
        return Poster::orderByDesc('created_at')->get();
    }

    public function getDpiInfoProperty(): array
    {
        $validator = app(DpiValidator::class);
        $info = [];

        foreach ($this->posters as $poster) {
            if (! file_exists($poster->original_path)) {
                continue;
            }

            $effectiveDpi = $validator->calculateEffectiveDpi($poster->original_path, $this->targetSize);
            $targetPixels = $validator->pixelsAtDpi($this->targetSize, $this->targetDpi);
            $imageInfo = @getimagesize($poster->original_path);

            if ($effectiveDpi !== null && $imageInfo && $targetPixels) {
                $scaleNeeded = max(
                    $targetPixels['width'] / $imageInfo[0],
                    $targetPixels['height'] / $imageInfo[1],
                );

                $info[$poster->id] = [
                    'current_dpi' => (int) $effectiveDpi,
                    'target_dpi' => $this->targetDpi,
                    'current_width' => $imageInfo[0],
                    'current_height' => $imageInfo[1],
                    'target_width' => $targetPixels['width'],
                    'target_height' => $targetPixels['height'],
                    'scale_needed' => round($scaleNeeded, 1),
                    'needs_upscale' => $scaleNeeded > 1.0,
                    'meets_minimum' => $effectiveDpi >= 150,
                    'meets_target' => $effectiveDpi >= $this->targetDpi,
                ];
            }
        }

        return $info;
    }

    public function getProgressProperty(): array
    {
        $progress = [];
        foreach ($this->posters as $poster) {
            $data = Cache::get("upscale_progress_{$poster->id}");
            if ($data) {
                $progress[$poster->id] = $data;
            }
        }
        return $progress;
    }

    private function getColorAdjust(): array
    {
        if ($this->brightness === 100 && $this->contrast === 0 && $this->saturation === 100) {
            return [];
        }

        return [
            'brightness' => $this->brightness,
            'contrast' => $this->contrast,
            'saturation' => $this->saturation,
        ];
    }

    public function render()
    {
        $validator = app(DpiValidator::class);

        return view('livewire.upscale-queue', [
            'posters' => $this->posters,
            'binaryAvailable' => $this->binaryAvailable,
            'models' => config('posterforge.upscale.models'),
            'printSizes' => $validator->allSizes(),
            'dpiInfo' => $this->dpiInfo,
            'progress' => $this->progress,
        ]);
    }
}
