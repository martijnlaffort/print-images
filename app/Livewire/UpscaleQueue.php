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
    public array $selected = [];
    public bool $selectAll = false;
    public bool $processing = false;

    public function startUpscale(): void
    {
        $posters = Poster::whereIn('id', $this->selected)->get();

        foreach ($posters as $poster) {
            UpscaleImage::dispatch($poster, $this->scale, $this->model);
        }

        $this->processing = true;
        $this->selected = [];
        $this->selectAll = false;
    }

    public function upscaleSingle(int $id): void
    {
        $poster = Poster::findOrFail($id);
        UpscaleImage::dispatch($poster, $this->scale, $this->model);
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
