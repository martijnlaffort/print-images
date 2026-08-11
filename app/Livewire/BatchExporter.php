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
    // Default = de verkoopformaten die printqc kent; A-formaten kunnen
    // nog steeds handmatig aangevinkt worden maar krijgen van printqc
    // altijd "handmatig beoordelen" (het script kent ze niet).
    public array $selectedSizes = ['50x70', '70x100'];
    public string $outputDir = '';
    public string $namingPattern = '{title}_{size}.png';
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

        // Print exports are always PNG — no JPEG in the print chain.
        $pattern = preg_replace('/\.\w+$/', '.png', $this->namingPattern);

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
            $since = $this->processingStartedAt;

            $failedTasks = BackgroundTask::where('type', 'export')
                ->where('status', 'failed')
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->get();

            $this->processing = false;
            $this->processingStartedAt = null;

            // De printqc-uitslag per bestand bepaalt wat er echt klaar
            // voor Gelato is — dat hoort in de afrondingsmelding thuis.
            $files = \App\Models\ExportFile::whereIn('poster_id', $this->selectedPosters)
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->get();
            $blocked = $files->where('status', 'blocked')->count();
            $review = $files->where('status', 'review')->count();
            $released = $files->where('status', 'released')->count();

            if ($failedTasks->isNotEmpty() || $blocked > 0) {
                $firstError = $failedTasks->first()?->error_message ?? '';
                $this->dispatch('toast', type: 'error', message: trim(
                    "Export afgerond met problemen: {$released} vrijgegeven, {$review} handmatig beoordelen, {$blocked} geblokkeerd. {$firstError}"
                ));
            } elseif ($review > 0) {
                Poster::whereIn('id', $this->selectedPosters)->update(['status' => 'exported']);
                $this->dispatch('toast', type: 'info', message: "Export afgerond: {$released} vrijgegeven, {$review} handmatig beoordelen — zie de QC-pagina.");
            } else {
                Poster::whereIn('id', $this->selectedPosters)->update(['status' => 'exported']);
                $this->dispatch('toast', type: 'success', message: "Export afgerond: alle {$released} bestand(en) door printqc vrijgegeven.");
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
        $skipped = 0;

        foreach ($posters as $poster) {
            $pattern = $poster->slug . '_*';
            $files = glob($outputDir . '/' . $pattern);
            foreach ($files as $file) {
                // De ZIP is het pakket richting Gelato: alleen échte
                // printbestanden die door de printqc-poort zijn
                // vrijgegeven. Geblokkeerde bestanden, json-bijlagen en
                // review-bestanden mogen de poort niet via de ZIP omzeilen.
                if (! preg_match('/\.png$/i', $file) || str_contains(basename($file), '_GEBLOKKEERD')) {
                    $skipped++;
                    continue;
                }

                $record = \App\Models\ExportFile::where('path', str_replace('\\', '/', $file))
                    ->orWhere('path', $file)
                    ->orderByDesc('id')
                    ->first();
                if ($record && $record->status !== 'released') {
                    $skipped++;
                    continue;
                }

                $zip->addFile($file, basename($file));
                $count++;
            }
        }

        $zip->close();

        if ($count === 0) {
            @unlink($zipPath);
            $this->dispatch('toast', type: 'error', message: $skipped > 0
                ? "Geen vrijgegeven exportbestanden — {$skipped} bestand(en) overgeslagen (geblokkeerd/handmatig beoordelen)."
                : 'No export files found.');
            return;
        }

        $this->dispatch('toast', type: 'success', message: "ZIP met {$count} vrijgegeven bestand(en)." . ($skipped > 0 ? " {$skipped} overgeslagen (geblokkeerd/beoordelen/bijlagen)." : ''));
        $this->redirect(route('file.download', ['path' => $zipPath]), navigate: false);
    }

    public function getPostersProperty()
    {
        $posters = Poster::whereIn('status', ['upscaled', 'mockups_ready', 'exported'])
            ->orderByDesc('created_at')
            ->get();

        // Haalbare formaten eenmalig bijvullen voor oudere posters, zodat
        // de blade alleen het (gecachte) attribuut hoeft te lezen en niet
        // per render de schijf/DB raakt.
        foreach ($posters as $poster) {
            if ($poster->feasible_sizes === null) {
                try {
                    $poster->refreshFeasibleSizes();
                } catch (\Throwable) {
                    // Onleesbaar bronbestand mag de lijst niet breken.
                }
            }
        }

        return $posters;
    }

    public function render()
    {
        return view('livewire.batch-exporter', [
            'posters' => $this->posters,
            'availableSizes' => (new DpiValidator())->allSizes(),
        ]);
    }
}
