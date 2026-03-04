<?php

namespace App\Livewire;

use App\Models\Poster;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;

class Dashboard extends Component
{
    use WithFileUploads;

    #[Validate(['photos.*' => 'file|mimes:jpg,jpeg,png,webp|max:102400'])]
    public $photos = [];
    public $search = '';
    public $selected = [];
    public bool $selectAll = false;

    public function updatedPhotos(): void
    {
        $this->validate();
        $count = 0;
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
            $count++;
        }

        $this->photos = [];
        $this->dispatch('toast', type: 'success', message: "{$count} poster(s) imported.");
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
                foreach ((array) $files as $file) {
                    if (file_exists($file)) {
                        Poster::createFromImport($file);
                        $count++;
                    }
                }
                $this->dispatch('toast', type: 'success', message: "{$count} poster(s) imported.");
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
        }
        $this->selected = array_values(array_diff($this->selected, [$id]));
        $this->dispatch('toast', type: 'success', message: 'Poster deleted.');
    }

    public function deleteSelected(): void
    {
        $count = count($this->selected);
        Poster::whereIn('id', $this->selected)->delete();
        $this->selected = [];
        $this->selectAll = false;
        $this->dispatch('toast', type: 'success', message: "{$count} poster(s) deleted.");
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
