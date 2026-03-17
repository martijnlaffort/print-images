<?php

namespace App\Livewire;

use App\Jobs\GenerateSizeVariants;
use App\Models\BackgroundTask;
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
    public bool $processing = false;
    public ?string $processingStartedAt = null;
    public int $exportTotal = 0;

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
        $count = $posters->count();

        $task = BackgroundTask::create([
            'type' => 'export',
            'name' => "Export: {$count} poster(s)",
            'status' => 'pending',
            'total_items' => $count,
        ]);

        foreach ($posters as $poster) {
            GenerateSizeVariants::dispatch(
                $poster,
                $this->selectedSizes,
                $outputDir,
                $pattern,
                $task->id,
            );
        }

        $this->exportTotal = $count;
        $this->processing = true;
        $this->processingStartedAt = now()->toDateTimeString();

        $this->dispatch('toast', type: 'info', message: "Export queued for {$count} poster(s).");
    }

    public function checkExportStatus(): void
    {
        $activeTasks = BackgroundTask::where('type', 'export')
            ->active()
            ->count();

        if ($activeTasks === 0) {
            $failedTasks = BackgroundTask::where('type', 'export')
                ->where('status', 'failed')
                ->when($this->processingStartedAt, fn ($q) => $q->where('created_at', '>=', $this->processingStartedAt))
                ->count();

            $this->processing = false;
            $this->processingStartedAt = null;

            if ($failedTasks > 0) {
                $this->dispatch('toast', type: 'error', message: "{$failedTasks} export job(s) failed.");
            } else {
                // Mark posters as exported
                Poster::whereIn('id', $this->selectedPosters)->update(['status' => 'exported']);
                $this->dispatch('toast', type: 'success', message: "All {$this->exportTotal} poster(s) exported.");
            }

            $this->exportTotal = 0;
        }
    }

    public function getExportCompletedProperty(): int
    {
        if (! $this->processing || $this->exportTotal === 0) {
            return 0;
        }

        $completed = BackgroundTask::where('type', 'export')
            ->when($this->processingStartedAt, fn ($q) => $q->where('created_at', '>=', $this->processingStartedAt))
            ->sum('completed_items');

        return (int) min($completed, $this->exportTotal);
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
