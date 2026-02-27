<?php

namespace App\Livewire;

use App\Models\Poster;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;

class Dashboard extends Component
{
    use WithFileUploads;

    public $photos = [];
    public $search = '';
    public $selected = [];
    public bool $selectAll = false;

    public function updatedPhotos(): void
    {
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

            Poster::createFromImport($fullPath);
        }

        $this->photos = [];
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
                foreach ((array) $files as $file) {
                    if (file_exists($file)) {
                        Poster::createFromImport($file);
                    }
                }
            }
        } catch (\Throwable) {
            // NativePHP dialog not available (dev mode without Electron)
            // File upload via drag-and-drop still works
        }
    }

    public function deletePoster(int $id): void
    {
        $poster = Poster::find($id);
        if ($poster) {
            $poster->delete();
        }
        $this->selected = array_values(array_diff($this->selected, [$id]));
    }

    public function deleteSelected(): void
    {
        Poster::whereIn('id', $this->selected)->delete();
        $this->selected = [];
        $this->selectAll = false;
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
            ->get();
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'posters' => $this->posters,
        ]);
    }
}
