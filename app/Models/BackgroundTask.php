<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BackgroundTask extends Model
{
    protected $fillable = [
        'type',
        'name',
        'status',
        'progress',
        'stage',
        'total_items',
        'completed_items',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'running']);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->whereIn('status', ['completed', 'failed'])
            ->where('completed_at', '>=', now()->subSeconds(60));
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function updateProgress(string $stage, int $percent): void
    {
        $this->update([
            'stage' => $stage,
            'progress' => min(100, $percent),
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'progress' => 100,
            'stage' => null,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'stage' => null,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    public function incrementCompleted(): void
    {
        self::query()
            ->where('id', $this->id)
            ->update([
                'completed_items' => \Illuminate\Support\Facades\DB::raw('completed_items + 1'),
                'progress' => \Illuminate\Support\Facades\DB::raw('CAST((completed_items + 1) * 100.0 / total_items AS INTEGER)'),
            ]);

        $this->refresh();

        if ($this->completed_items >= $this->total_items) {
            $this->markCompleted();
        }
    }

    public static function cleanupOld(): void
    {
        static::whereIn('status', ['completed', 'failed'])
            ->where('completed_at', '<', now()->subMinutes(5))
            ->delete();
    }
}
