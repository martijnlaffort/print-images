<?php

namespace App\Livewire;

use App\Jobs\GenerateMockup;
use App\Models\BackgroundTask;
use App\Models\GeneratedMockup;
use App\Models\MockupTemplate;
use App\Models\Poster;
use App\Services\MockupService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class MockupGenerator extends Component
{
    public array $selectedPosters = [];
    public ?int $selectedTemplate = null;
    public string $categoryFilter = '';
    public string $fitMode = 'fill';
    public string $outputFormat = 'jpg';
    public int $outputQuality = 92;
    public string $framePreset = 'none';
    public string $overlayText = '';
    public int $overlayFontSize = 32;
    public string $overlayFontColor = 'white';
    public string $overlayPosition = 'South';
    public array $slotAssignments = [];

    // Preview
    public ?string $previewImage = null;

    // Async batch progress
    public bool $processing = false;
    public int $mockupTotal = 0;
    public ?string $processingStartedAt = null;

    public function selectTemplate(int $id): void
    {
        $this->selectedTemplate = $id;
        $this->previewImage = null;
        $this->initSlotAssignments();
    }

    public function assignPosterToSlot(int $slotIndex, ?int $posterId): void
    {
        $this->slotAssignments[$slotIndex] = $posterId;
    }

    public function getSelectedTemplateSlotsProperty(): array
    {
        if (! $this->selectedTemplate) {
            return [];
        }

        $template = MockupTemplate::find($this->selectedTemplate);

        return $template ? $template->getAllSlots() : [];
    }

    public function getIsMultiSlotProperty(): bool
    {
        return count($this->selectedTemplateSlots) > 1;
    }

    private function initSlotAssignments(): void
    {
        $slots = $this->selectedTemplateSlots;
        $this->slotAssignments = [];

        foreach ($slots as $i => $slot) {
            $this->slotAssignments[$i] = $this->selectedPosters[$i] ?? null;
        }
    }

    // --- Preview ---

    public function previewMockup(): void
    {
        if (! $this->selectedTemplate || empty($this->selectedPosters)) {
            $this->dispatch('toast', type: 'error', message: 'Select a template and at least one poster.');
            return;
        }

        $template = MockupTemplate::findOrFail($this->selectedTemplate);
        $slots = $template->getAllSlots();
        $mockupService = app(MockupService::class);

        // Determine poster for first slot
        if (count($slots) > 1) {
            $posterId = collect($this->slotAssignments)->filter()->first();
            if (! $posterId) {
                $this->dispatch('toast', type: 'error', message: 'Assign at least one poster for preview.');
                return;
            }
            $poster = Poster::find($posterId);
        } else {
            $poster = Poster::find($this->selectedPosters[0]);
        }

        if (! $poster) {
            return;
        }

        $posterPath = $poster->upscaled_path ?? $poster->original_path;
        $previewPath = sys_get_temp_dir() . '/mockup_preview_' . uniqid() . '.jpg';

        try {
            $mockupService->generatePreview(
                $posterPath,
                $template->background_path,
                $slots[0]['corners'],
                $previewPath,
                [
                    'shadowPath' => $template->shadow_path,
                    'brightness' => $template->brightness_adjust,
                    'fitMode' => $this->fitMode,
                    'format' => 'jpg',
                    'quality' => 75,
                    'framePreset' => $this->framePreset,
                ],
            );

            $this->previewImage = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($previewPath));
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'Preview failed: ' . $e->getMessage());
        } finally {
            @unlink($previewPath);
        }
    }

    // --- Generate (async) ---

    public function generateForTemplate(int $templateId): void
    {
        $template = MockupTemplate::findOrFail($templateId);
        $slots = $template->getAllSlots();
        $textOverlay = $this->getTextOverlay();

        if (count($slots) > 1) {
            $this->generateMultiSlot($template, $textOverlay);
            return;
        }

        $posters = Poster::whereIn('id', $this->selectedPosters)->get();

        $this->mockupTotal = $posters->count();
        $this->processing = true;
        $this->processingStartedAt = now()->toDateTimeString();

        $task = BackgroundTask::create([
            'type' => 'mockup',
            'name' => "Mockups: {$posters->count()} images",
            'status' => 'pending',
            'total_items' => $posters->count(),
        ]);

        foreach ($posters as $poster) {
            GenerateMockup::dispatch(
                $poster,
                $template,
                $this->fitMode,
                $this->outputFormat,
                $this->outputQuality,
                $this->framePreset,
                $textOverlay,
                $task->id,
            );
        }

        $this->dispatch('toast', type: 'info', message: "Queued {$posters->count()} mockup(s).");
    }

    private function generateMultiSlot(MockupTemplate $template, ?array $textOverlay): void
    {
        $assignments = collect($this->slotAssignments)->filter();

        if ($assignments->isEmpty()) {
            $this->dispatch('toast', type: 'error', message: 'Assign at least one poster to a slot.');
            return;
        }

        $posters = [];
        foreach ($this->slotAssignments as $posterId) {
            $posters[] = $posterId ? Poster::find($posterId) : null;
        }

        // Fill gaps: use the first assigned poster for empty slots
        $firstPoster = collect($posters)->filter()->first();
        $posters = array_map(fn ($p) => $p ?? $firstPoster, $posters);

        $this->mockupTotal = 1;
        $this->processing = true;
        $this->processingStartedAt = now()->toDateTimeString();

        $task = BackgroundTask::create([
            'type' => 'mockup',
            'name' => 'Mockup: multi-slot',
            'status' => 'pending',
            'total_items' => 1,
        ]);

        GenerateMockup::dispatch(
            $posters,
            $template,
            $this->fitMode,
            $this->outputFormat,
            $this->outputQuality,
            $this->framePreset,
            $textOverlay,
            $task->id,
        );

        $this->dispatch('toast', type: 'info', message: 'Queued multi-image mockup.');
    }

    public function generateAll(): void
    {
        $posters = Poster::whereIn('id', $this->selectedPosters)->get();
        $templates = MockupTemplate::query()
            ->when($this->categoryFilter, fn ($q) => $q->where('category', $this->categoryFilter))
            ->get();

        $textOverlay = $this->getTextOverlay();

        $count = 0;
        $task = BackgroundTask::create([
            'type' => 'mockup',
            'name' => 'Mockups: all templates',
            'status' => 'pending',
            'total_items' => $posters->count() * $templates->count(),
        ]);

        foreach ($posters as $poster) {
            foreach ($templates as $template) {
                GenerateMockup::dispatch(
                    $poster,
                    $template,
                    $this->fitMode,
                    $this->outputFormat,
                    $this->outputQuality,
                    $this->framePreset,
                    $textOverlay,
                    $task->id,
                );
                $count++;
            }
        }

        $this->mockupTotal = $count;
        $this->processing = true;
        $this->processingStartedAt = now()->toDateTimeString();

        $this->dispatch('toast', type: 'info', message: "Queued {$count} mockup(s).");
    }

    public function checkMockupStatus(): void
    {
        $activeTasks = BackgroundTask::where('type', 'mockup')
            ->active()
            ->count();

        if ($activeTasks === 0) {
            $failedTasks = BackgroundTask::where('type', 'mockup')
                ->where('status', 'failed')
                ->when($this->processingStartedAt, fn ($q) => $q->where('created_at', '>=', $this->processingStartedAt))
                ->count();

            $this->processing = false;
            $this->processingStartedAt = null;

            if ($failedTasks > 0) {
                $this->dispatch('toast', type: 'error', message: "{$failedTasks} mockup job(s) failed.");
            } else {
                $this->dispatch('toast', type: 'success', message: "All {$this->mockupTotal} mockup(s) generated.");
            }

            $this->mockupTotal = 0;
        }
    }

    public function getMockupCompletedProperty(): int
    {
        if (! $this->processing || $this->mockupTotal === 0) {
            return 0;
        }

        $completed = BackgroundTask::where('type', 'mockup')
            ->when($this->processingStartedAt, fn ($q) => $q->where('created_at', '>=', $this->processingStartedAt))
            ->sum('completed_items');

        return (int) min($completed, $this->mockupTotal);
    }

    // --- Download / Delete ---

    public function downloadZip(): void
    {
        $mockups = GeneratedMockup::whereIn('poster_id', $this->selectedPosters)
            ->get();

        if ($mockups->isEmpty()) {
            $this->dispatch('toast', type: 'error', message: 'No mockups to download.');
            return;
        }

        $zipPath = storage_path('app/mockups/mockups_' . now()->format('Y-m-d_His') . '.zip');
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            $this->dispatch('toast', type: 'error', message: 'Failed to create ZIP file.');
            return;
        }

        $count = 0;
        foreach ($mockups as $mockup) {
            if (file_exists($mockup->output_path)) {
                $zip->addFile($mockup->output_path, basename($mockup->output_path));
                $count++;
            }
        }

        $zip->close();

        if ($count === 0) {
            @unlink($zipPath);
            $this->dispatch('toast', type: 'error', message: 'No mockup files found.');
            return;
        }

        $this->dispatch('toast', type: 'success', message: "ZIP created with {$count} mockup(s).");
        $this->redirect(route('file.download', ['path' => $zipPath]), navigate: false);
    }

    // --- Computed Properties ---

    public function getPostersProperty()
    {
        return Poster::whereIn('status', ['upscaled', 'mockups_ready', 'exported'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function getTemplatesProperty()
    {
        return MockupTemplate::query()
            ->when($this->categoryFilter, fn ($q) => $q->where('category', $this->categoryFilter))
            ->orderBy('name')
            ->get();
    }

    public function getCategoriesProperty(): array
    {
        return MockupTemplate::distinct()->pluck('category')->toArray();
    }

    public function getMockupsProperty()
    {
        return GeneratedMockup::with(['poster', 'template'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    public function deleteMockup(int $id): void
    {
        $mockup = GeneratedMockup::findOrFail($id);
        if (file_exists($mockup->output_path)) {
            @unlink($mockup->output_path);
        }
        $mockup->delete();
        $this->dispatch('toast', type: 'success', message: 'Mockup deleted.');
    }

    public function getFramePresetsProperty(): array
    {
        return MockupService::FRAME_PRESETS;
    }

    private function getTextOverlay(): ?array
    {
        if (empty($this->overlayText)) {
            return null;
        }

        return [
            'text' => $this->overlayText,
            'fontSize' => $this->overlayFontSize,
            'fontColor' => $this->overlayFontColor,
            'position' => $this->overlayPosition,
        ];
    }

    public function render()
    {
        return view('livewire.mockup-generator', [
            'posters' => $this->posters,
            'templates' => $this->templates,
            'categories' => $this->categories,
            'mockups' => $this->mockups,
            'framePresets' => $this->framePresets,
        ]);
    }
}
