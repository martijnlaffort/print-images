<?php

namespace App\Livewire;

use App\Jobs\GenerateSizeVariants;
use App\Models\Poster;
use App\Services\DpiValidator;
use Livewire\Component;

class BatchExporter extends Component
{
    public array $selectedPosters = [];
    public array $selectedSizes = ['A4', 'A3'];
    public string $outputDir = '';
    public string $namingPattern = '{title}_{size}.png';
    public array $dpiResults = [];
    public bool $exporting = false;

    public function mount(): void
    {
        $this->outputDir = storage_path('app/exports');
    }

    public function selectOutputDir(): void
    {
        try {
            $dir = \Native\Laravel\Facades\Dialog::new()
                ->title('Select export folder')
                ->asSheet()
                ->openDirectory();

            if ($dir) {
                $this->outputDir = $dir;
            }
        } catch (\Throwable) {
            // Fallback if NativePHP dialog not available
        }
    }

    public function validateDpi(): void
    {
        $validator = new DpiValidator();
        $this->dpiResults = [];

        $posters = Poster::whereIn('id', $this->selectedPosters)->get();

        foreach ($posters as $poster) {
            $imagePath = $poster->upscaled_path ?? $poster->original_path;
            if (file_exists($imagePath)) {
                $this->dpiResults[$poster->id] = $validator->validateAll($imagePath);
            }
        }
    }

    public function exportAll(): void
    {
        if (empty($this->selectedPosters) || empty($this->selectedSizes)) {
            return;
        }

        $outputDir = $this->outputDir ?: storage_path('app/exports');

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $posters = Poster::whereIn('id', $this->selectedPosters)->get();

        foreach ($posters as $poster) {
            GenerateSizeVariants::dispatch(
                $poster,
                $this->selectedSizes,
                $outputDir,
                $this->namingPattern,
            );

            $poster->update(['status' => 'exported']);
        }

        $this->exporting = true;
    }

    public function getPostersProperty()
    {
        return Poster::whereIn('status', ['upscaled', 'mockups_ready', 'exported'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.batch-exporter', [
            'posters' => $this->posters,
            'availableSizes' => DpiValidator::sizeNames(),
        ]);
    }
}
