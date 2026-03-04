<?php

namespace App\Livewire;

use App\Jobs\UpscaleImage;
use App\Models\Poster;
use App\Services\UpscaleService;
use Livewire\Component;

class UpscaleQueue extends Component
{
    public int $scale = 4;
    public string $model = 'realesrgan-x4plus';
    public int $denoise = 50;
    public array $selected = [];
    public bool $selectAll = false;
    public bool $processing = false;
    public ?string $processingStartedAt = null;

    public function startUpscale(): void
    {
        $posters = Poster::whereIn('id', $this->selected)->get();
        $count = $posters->count();

        foreach ($posters as $poster) {
            UpscaleImage::dispatch($poster, $this->scale, $this->model, $this->denoise);
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
        UpscaleImage::dispatch($poster, $this->scale, $this->model, $this->denoise);
        $this->processing = true;
        $this->processingStartedAt = $this->processingStartedAt ?? now()->toDateTimeString();
        $this->dispatch('toast', type: 'info', message: 'Upscale job queued.');
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
            $this->selected = Poster::where('status', 'imported')
                ->pluck('id')
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

    public function render()
    {
        return view('livewire.upscale-queue', [
            'posters' => $this->posters,
            'binaryAvailable' => $this->binaryAvailable,
            'models' => config('posterforge.upscale.models'),
        ]);
    }
}
