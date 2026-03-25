<?php

namespace App\Livewire;

use App\Models\BackgroundTask;
use App\Models\Poster;
use App\Models\PosterActivity;
use App\Services\ShopService;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Dashboard extends Component
{
    use WithFileUploads, WithPagination;

    #[Validate(['photos.*' => 'file|mimes:jpg,jpeg,png,webp|max:102400'])]
    public $photos = [];
    public $search = '';
    public $selected = [];
    public bool $selectAll = false;
    public bool $showTrash = false;
    public ?int $historyPoster = null;
    public ?int $detailPoster = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPhotos(): void
    {
        $this->validate();
        $count = 0;
        $skipped = 0;
        foreach ($this->photos as $photo) {
            $filename = pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME);
            $extension = $photo->getClientOriginalExtension();

            $storagePath = storage_path('app/originals');
            if (! is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $savedName = Str::slug($filename) . '.' . $extension;
            $fullPath = $storagePath . '/' . $savedName;

            copy($photo->getRealPath(), $fullPath);

            $poster = Poster::createFromImport($fullPath);
            if ($poster) {
                PosterActivity::log($poster->id, 'imported', ['filename' => $photo->getClientOriginalName()]);
                $count++;
            } else {
                $skipped++;
            }
        }

        $this->photos = [];

        $message = "{$count} poster(s) imported.";
        if ($skipped > 0) {
            $message .= " Skipped {$skipped} duplicate(s).";
        }
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function importViaDialog(): void
    {
        try {
            $files = \Native\Laravel\Facades\Dialog::new()
                ->title('Select poster images')
                ->filter('Images', ['jpg', 'jpeg', 'png', 'webp'])
                ->multiple()
                ->open();

            if ($files) {
                $count = 0;
                $skipped = 0;
                foreach ((array) $files as $file) {
                    if (file_exists($file)) {
                        $poster = Poster::createFromImport($file);
                        if ($poster) {
                            PosterActivity::log($poster->id, 'imported', ['filename' => basename($file)]);
                            $count++;
                        } else {
                            $skipped++;
                        }
                    }
                }
                $message = "{$count} poster(s) imported.";
                if ($skipped > 0) {
                    $message .= " Skipped {$skipped} duplicate(s).";
                }
                $this->dispatch('toast', type: 'success', message: $message);
            }
        } catch (\Throwable) {
            $this->dispatch('toast', type: 'error', message: 'Import dialog not available. Use drag-and-drop instead.');
        }
    }

    public function deletePoster(int $id): void
    {
        $poster = Poster::find($id);
        if ($poster) {
            $poster->delete();
            PosterActivity::log($id, 'deleted');
        }
        $this->selected = array_values(array_diff($this->selected, [(string) $id]));
        $this->dispatch('toast', type: 'success', message: 'Poster moved to trash.');
    }

    public function deleteSelected(): void
    {
        $posters = Poster::whereIn('id', $this->selected)->get();
        foreach ($posters as $poster) {
            $poster->delete();
            PosterActivity::log($poster->id, 'deleted');
        }
        $count = $posters->count();
        $this->selected = [];
        $this->selectAll = false;
        $this->dispatch('toast', type: 'success', message: "{$count} poster(s) moved to trash.");
    }

    public function restorePoster(int $id): void
    {
        Poster::onlyTrashed()->findOrFail($id)->restore();
        PosterActivity::log($id, 'restored');
        $this->dispatch('toast', type: 'success', message: 'Poster restored.');
    }

    public function permanentlyDelete(int $id): void
    {
        $poster = Poster::onlyTrashed()->findOrFail($id);
        $this->deleteFiles($poster);
        // Delete mockup output files
        $poster->generatedMockups()->each(function ($mockup) {
            if ($mockup->output_path && file_exists($mockup->output_path)) {
                @unlink($mockup->output_path);
            }
        });
        // Delete thumbnail
        $thumbPath = storage_path("app/thumbnails/{$id}_thumb.jpg");
        if (file_exists($thumbPath)) {
            @unlink($thumbPath);
        }
        PosterActivity::log($id, 'permanently_deleted');
        $poster->forceDelete();
        $this->dispatch('toast', type: 'success', message: 'Poster permanently deleted.');
    }

    public function emptyTrash(): void
    {
        $trashed = Poster::onlyTrashed()->get();
        foreach ($trashed as $poster) {
            $this->deleteFiles($poster);
            $poster->generatedMockups()->each(function ($mockup) {
                if ($mockup->output_path && file_exists($mockup->output_path)) {
                    @unlink($mockup->output_path);
                }
            });
            $thumbPath = storage_path("app/thumbnails/{$poster->id}_thumb.jpg");
            if (file_exists($thumbPath)) {
                @unlink($thumbPath);
            }
            $poster->forceDelete();
        }
        $this->dispatch('toast', type: 'success', message: 'Trash emptied.');
    }

    private function deleteFiles(Poster $poster): void
    {
        if ($poster->original_path && file_exists($poster->original_path)) {
            @unlink($poster->original_path);
        }
        if ($poster->upscaled_path && file_exists($poster->upscaled_path)) {
            @unlink($poster->upscaled_path);
        }
    }

    public function showHistory(int $id): void
    {
        $this->historyPoster = $id;
    }

    public function closeHistory(): void
    {
        $this->historyPoster = null;
    }

    public function showDetail(int $id): void
    {
        $this->detailPoster = $id;
    }

    public function closeDetail(): void
    {
        $this->detailPoster = null;
    }

    public function getDetailProperty(): ?array
    {
        if (! $this->detailPoster) {
            return null;
        }

        $poster = Poster::with(['generatedMockups', 'activities'])->find($this->detailPoster);
        if (! $poster) {
            return null;
        }

        $fileInfo = [];
        if ($poster->original_path && file_exists($poster->original_path)) {
            $imageInfo = @getimagesize($poster->original_path);
            $fileInfo['original'] = [
                'size' => $this->formatFileSize(filesize($poster->original_path)),
                'width' => $imageInfo ? $imageInfo[0] : null,
                'height' => $imageInfo ? $imageInfo[1] : null,
            ];
        }

        if ($poster->upscaled_path && file_exists($poster->upscaled_path)) {
            $imageInfo = @getimagesize($poster->upscaled_path);
            $fileInfo['upscaled'] = [
                'size' => $this->formatFileSize(filesize($poster->upscaled_path)),
                'width' => $imageInfo ? $imageInfo[0] : null,
                'height' => $imageInfo ? $imageInfo[1] : null,
            ];
        }

        return [
            'poster' => $poster,
            'fileInfo' => $fileInfo,
            'mockups' => $poster->generatedMockups,
            'activities' => $poster->activities()->orderByDesc('created_at')->take(20)->get(),
        ];
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    public function getHistoryProperty()
    {
        if (! $this->historyPoster) {
            return collect();
        }

        return PosterActivity::where('poster_id', $this->historyPoster)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getCardProgressProperty(): array
    {
        $progress = [];

        // Check cache-based upscale progress
        foreach ($this->posters as $poster) {
            $data = Cache::get("upscale_progress_{$poster->id}");
            if ($data && ($data['stage'] ?? '') !== 'completed') {
                $progress[$poster->id] = [
                    'type' => 'upscale',
                    'stage' => $data['stage'] ?? 'processing',
                    'percent' => $data['percent'] ?? 0,
                ];
            }
        }

        // Check active background tasks for mockup/export jobs
        $activeTasks = BackgroundTask::active()->get();
        foreach ($activeTasks as $task) {
            // Task names follow pattern "Upscale: Title", "Mockup: Title", etc.
            $name = $task->name ?? '';
            if (preg_match('/^(Upscale|Mockup|Export|Pipeline):\s+(.+)$/i', $name, $matches)) {
                $type = strtolower($matches[1]);
                $title = $matches[2];
                // Find matching poster by title
                foreach ($this->posters as $poster) {
                    if ($poster->title === $title && ! isset($progress[$poster->id])) {
                        $progress[$poster->id] = [
                            'type' => $type,
                            'stage' => $task->stage ?? $task->status,
                            'percent' => $task->progress ?? 0,
                        ];
                    }
                }
            }
        }

        return $progress;
    }

    public function getTrashedPostersProperty()
    {
        return Poster::onlyTrashed()->orderByDesc('deleted_at')->get();
    }

    public function updatedSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selected = $this->getPostersProperty()->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            $this->selected = [];
        }
    }

    public function getPostersProperty()
    {
        return Poster::query()
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->orderByDesc('created_at')
            ->paginate(24);
    }

    public function pushToShop(int $id): void
    {
        $poster = Poster::with('generatedMockups')->find($id);

        if (! $poster) {
            $this->dispatch('toast', type: 'error', message: 'Poster not found.');

            return;
        }

        try {
            $shop = ShopService::make();
            $shop->authenticate();

            $result = $shop->createProduct([
                'title_nl' => $poster->title,
                'title_en' => $poster->title,
                'active' => false,
            ]);

            $productId = $result['id'];

            // Upload upscaled image (or original) as main image
            $mainImage = $poster->upscaled_path ?? $poster->original_path;
            if ($mainImage && file_exists($mainImage)) {
                $shop->uploadMedia($productId, $mainImage, 'main_image');
            }

            // Upload mockups as gallery images
            foreach ($poster->generatedMockups as $mockup) {
                if ($mockup->output_path && file_exists($mockup->output_path)) {
                    $shop->uploadMedia($productId, $mockup->output_path, 'gallery');
                }
            }

            $shopUrl = rtrim(config('shop.url'), '/');
            $poster->update(['pushed_at' => now()]);

            PosterActivity::log($poster->id, 'pushed_to_shop', [
                'product_id' => $productId,
                'slug' => $result['slug'] ?? null,
            ]);

            $this->dispatch('toast', type: 'success', message: "Pushed to webshop! Product ID: {$productId}");
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'Push failed: ' . $e->getMessage());
        }
    }

    public function importByPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                \App\Models\Poster::createFromImport($path);
            }
        }
    }

    public function render()
    {
        $cardProgress = $this->cardProgress;

        return view('livewire.dashboard', [
            'posters' => $this->posters,
            'trashedPosters' => $this->showTrash ? $this->trashedPosters : collect(),
            'trashedCount' => Poster::onlyTrashed()->count(),
            'cardProgress' => $cardProgress,
            'hasActiveJobs' => ! empty($cardProgress),
            'detail' => $this->detail,
        ]);
    }
}
