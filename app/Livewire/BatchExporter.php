<?php

namespace App\Livewire;

use App\Jobs\GenerateSizeVariants;
use App\Models\Poster;
use App\Models\Setting;
use App\Services\DpiValidator;
use Livewire\Component;

class BatchExporter extends Component
{
    public array $selectedPosters = [];
    public array $selectedSizes = ['A4', 'A3'];
    public string $outputDir = '';
    public string $namingPattern = '{title}_{size}.png';
    public string $outputFormat = 'png';
    public int $outputQuality = 92;
    public array $dpiResults = [];

    public function mount(): void
    {
        $this->outputDir = Setting::get('export.default_dir', storage_path('app/exports'));
        $this->namingPattern = Setting::get('naming.size_variant', config('posterforge.naming.size_variant', '{title}_{size}.png'));
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

        $this->dispatch('toast', type: 'info', message: 'DPI validation complete.');
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

        // Adjust naming pattern extension to match format
        $ext = $this->outputFormat === 'jpg' ? 'jpg' : 'png';
        $pattern = preg_replace('/\.\w+$/', ".{$ext}", $this->namingPattern);

        $posters = Poster::whereIn('id', $this->selectedPosters)->get();

        $count = 0;
        foreach ($posters as $poster) {
            GenerateSizeVariants::dispatchSync(
                $poster,
                $this->selectedSizes,
                $outputDir,
                $pattern,
            );

            $poster->update(['status' => 'exported']);
            $count++;
        }

        $this->dispatch('toast', type: 'success', message: "Exported {$count} poster(s).");
    }

    public function downloadZip(): void
    {
        $outputDir = $this->outputDir ?: storage_path('app/exports');

        if (! is_dir($outputDir)) {
            $this->dispatch('toast', type: 'error', message: 'No exports found.');
            return;
        }

        $zipPath = storage_path('app/exports/exports_' . now()->format('Y-m-d_His') . '.zip');
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            $this->dispatch('toast', type: 'error', message: 'Failed to create ZIP file.');
            return;
        }

        $posters = Poster::whereIn('id', $this->selectedPosters)->get();
        $count = 0;

        foreach ($posters as $poster) {
            $pattern = $poster->slug . '_*';
            $files = glob($outputDir . '/' . $pattern);
            foreach ($files as $file) {
                $zip->addFile($file, basename($file));
                $count++;
            }
        }

        $zip->close();

        if ($count === 0) {
            @unlink($zipPath);
            $this->dispatch('toast', type: 'error', message: 'No export files found.');
            return;
        }

        $this->dispatch('toast', type: 'success', message: "ZIP created with {$count} file(s).");
        $this->redirect(route('file.download', ['path' => $zipPath]), navigate: false);
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
            'availableSizes' => (new DpiValidator())->allSizes(),
        ]);
    }
}
