<?php

namespace App\Livewire;

use App\Models\BackgroundTask;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TaskStatusIndicator extends Component
{
    public array $previouslyActive = [];

    public function mount(): void
    {
        $this->previouslyActive = BackgroundTask::active()
            ->pluck('id')
            ->toArray();
    }

    public function refreshTasks(): void
    {
        BackgroundTask::cleanupOld();

        // Detect stale tasks (running for more than 15 minutes)
        BackgroundTask::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(15))
            ->each(fn (BackgroundTask $task) => $task->markFailed('Task timed out'));

        // Detect newly completed tasks for toast notifications
        $currentActive = BackgroundTask::active()->pluck('id')->toArray();
        $justFinished = array_diff($this->previouslyActive, $currentActive);

        if (! empty($justFinished)) {
            $completed = BackgroundTask::whereIn('id', $justFinished)
                ->where('status', 'completed')
                ->get();

            foreach ($completed as $task) {
                $this->dispatch('toast', type: 'success', message: "{$task->name} completed.");
            }

            $failed = BackgroundTask::whereIn('id', $justFinished)
                ->where('status', 'failed')
                ->get();

            foreach ($failed as $task) {
                $this->dispatch('toast', type: 'error', message: "{$task->name} failed.");
            }
        }

        $this->previouslyActive = $currentActive;
    }

    public function dismissTask(int $id): void
    {
        BackgroundTask::where('id', $id)
            ->whereIn('status', ['completed', 'failed'])
            ->delete();
    }

    #[Computed]
    public function tasks()
    {
        return BackgroundTask::query()
            ->where(function ($q) {
                $q->active();
            })
            ->orWhere(function ($q) {
                $q->recent();
            })
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function activeCount(): int
    {
        return BackgroundTask::active()->count();
    }

    #[Computed]
    public function hasAnyTasks(): bool
    {
        return $this->tasks->isNotEmpty();
    }

    public function render()
    {
        return view('livewire.task-status-indicator');
    }
}
