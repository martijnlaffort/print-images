<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\Poster;
use App\Services\QualityControlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RunQcBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    /**
     * Either $posterIds (QC on imported posters) or $folderPath
     * (QC on every image in a directory) — not both.
     */
    public function __construct(
        public array $posterIds,
        public ?string $folderPath,
        public int $backgroundTaskId,
        public ?string $batchId = null,
    ) {
        $this->queue = 'upscale';
        $this->batchId ??= (string) Str::uuid();
    }

    public function handle(QualityControlService $qcService): void
    {
        set_time_limit(0);

        $task = BackgroundTask::find($this->backgroundTaskId);
        if (! $task) {
            return;
        }

        $task->markRunning();

        $items = $this->collectItems();
        $total = max(count($items), 1);
        $done = 0;
        $failed = [];

        foreach ($items as $item) {
            $label = $item['poster']?->title ?? basename($item['path']);
            $task->updateProgress("QC: {$label}", (int) ($done / $total * 100));

            try {
                $qcService->runAndStore(
                    $item['path'],
                    'source',
                    $item['poster']?->id,
                    batchId: $this->batchId,
                );
            } catch (\Throwable $e) {
                $failed[] = $label . ' (' . $e->getMessage() . ')';
            }

            $done++;
            $task->updateProgress("QC klaar: {$label}", min((int) ($done / $total * 100), 99));
        }

        if ($failed) {
            $task->updateProgress('QC mislukt voor: ' . implode('; ', array_slice($failed, 0, 5)), 99);
        }

        $task->markCompleted();
    }

    public function failed(\Throwable $e): void
    {
        BackgroundTask::find($this->backgroundTaskId)?->markFailed($e->getMessage());
    }

    private function collectItems(): array
    {
        if ($this->folderPath) {
            $files = collect(glob(rtrim($this->folderPath, '/\\') . '/*.{png,PNG,jpg,JPG,jpeg,JPEG,webp,WEBP,tif,TIF,tiff,TIFF}', GLOB_BRACE))
                ->unique()
                ->values();

            return $files->map(fn ($f) => ['path' => $f, 'poster' => null])->all();
        }

        return Poster::whereIn('id', $this->posterIds)
            ->get()
            ->filter(fn ($p) => file_exists($p->original_path))
            ->map(fn ($p) => ['path' => $p->upscaled_path && file_exists($p->upscaled_path) ? $p->upscaled_path : $p->original_path, 'poster' => $p])
            ->values()
            ->all();
    }
}
