<?php

namespace App\Livewire;

use App\Jobs\RunQcBatch;
use App\Models\BackgroundTask;
use App\Models\Poster;
use App\Models\QcReport;
use Livewire\Component;
use Livewire\WithPagination;

class QualityControl extends Component
{
    use WithPagination;

    public string $verdictFilter = '';
    public ?int $detailReport = null;
    public bool $processing = false;
    public ?int $batchTaskId = null;

    public function runForPoster(int $posterId): void
    {
        $poster = Poster::findOrFail($posterId);

        $task = BackgroundTask::create([
            'type' => 'qc',
            'name' => "QC: {$poster->title}",
            'status' => 'pending',
            'total_items' => 1,
        ]);

        RunQcBatch::dispatch([$posterId], null, $task->id);

        $this->processing = true;
        $this->batchTaskId = $task->id;
        $this->dispatch('toast', type: 'info', message: "QC gestart voor {$poster->title}.");
    }

    public function runForAllPosters(): void
    {
        $ids = Poster::pluck('id')->all();

        if (! $ids) {
            $this->dispatch('toast', type: 'error', message: 'Geen posters gevonden.');
            return;
        }

        $task = BackgroundTask::create([
            'type' => 'qc',
            'name' => 'QC: ' . count($ids) . ' posters',
            'status' => 'pending',
            'total_items' => count($ids),
        ]);

        RunQcBatch::dispatch($ids, null, $task->id);

        $this->processing = true;
        $this->batchTaskId = $task->id;
        $this->dispatch('toast', type: 'info', message: 'QC gestart voor ' . count($ids) . ' poster(s).');
    }

    public function runForFolder(): void
    {
        try {
            $dir = \Native\Laravel\Dialog::new()
                ->title('Kies een map met afbeeldingen voor QC')
                ->folders()
                ->open();
        } catch (\Throwable) {
            $this->dispatch('toast', type: 'error', message: 'Map-dialoog niet beschikbaar buiten de desktop-app.');
            return;
        }

        if (! $dir) {
            return;
        }

        $task = BackgroundTask::create([
            'type' => 'qc',
            'name' => 'QC map: ' . basename($dir),
            'status' => 'pending',
            'total_items' => 1,
        ]);

        RunQcBatch::dispatch([], $dir, $task->id);

        $this->processing = true;
        $this->batchTaskId = $task->id;
        $this->dispatch('toast', type: 'info', message: "QC gestart voor map: {$dir}");
    }

    public function checkStatus(): void
    {
        if (! $this->batchTaskId) {
            return;
        }

        $task = BackgroundTask::find($this->batchTaskId);

        if (! $task || in_array($task->status, ['completed', 'failed'])) {
            $this->processing = false;
            $this->batchTaskId = null;

            if ($task?->status === 'failed') {
                $this->dispatch('toast', type: 'error', message: 'QC mislukt: ' . ($task->error_message ?? 'Onbekende fout'));
            } else {
                $this->dispatch('toast', type: 'success', message: 'QC afgerond.');
            }
        }
    }

    public function showReport(int $id): void
    {
        $this->detailReport = $id;
    }

    public function closeReport(): void
    {
        $this->detailReport = null;
    }

    public function updatedVerdictFilter(): void
    {
        $this->resetPage();
    }

    public function getReportProperty(): ?QcReport
    {
        return $this->detailReport
            ? QcReport::with('poster')->find($this->detailReport)
            : null;
    }

    public function getSummaryProperty(): array
    {
        $latest = QcReport::selectRaw('MAX(id) as id')
            ->groupBy('source_path')
            ->pluck('id');

        $counts = QcReport::whereIn('id', $latest)
            ->selectRaw('verdict, COUNT(*) as n')
            ->groupBy('verdict')
            ->pluck('n', 'verdict');

        return [
            'pass' => (int) ($counts['pass'] ?? 0),
            'warn' => (int) ($counts['warn'] ?? 0),
            'fail' => (int) ($counts['fail'] ?? 0),
        ];
    }

    public function getTaskProgressProperty(): ?array
    {
        if (! $this->batchTaskId) {
            return null;
        }

        $task = BackgroundTask::find($this->batchTaskId);

        return $task ? ['stage' => $task->stage, 'progress' => $task->progress] : null;
    }

    public function render()
    {
        $reports = QcReport::with('poster')
            ->when($this->verdictFilter, fn ($q) => $q->where('verdict', $this->verdictFilter))
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('livewire.quality-control', [
            'reports' => $reports,
            'summary' => $this->summary,
            'report' => $this->report,
            'taskProgress' => $this->taskProgress,
        ]);
    }
}
