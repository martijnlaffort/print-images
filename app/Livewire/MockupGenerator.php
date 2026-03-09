<?php

namespace App\Livewire;

use App\Jobs\GenerateMockup;
use App\Models\GeneratedMockup;
use App\Models\MockupTemplate;
use App\Models\Poster;
use App\Services\MockupService;
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

    public function selectTemplate(int $id): void
    {
        $this->selectedTemplate = $id;
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

        foreach ($posters as $poster) {
            GenerateMockup::dispatchSync(
                $poster,
                $template,
                $this->fitMode,
                $this->outputFormat,
                $this->outputQuality,
                $this->framePreset,
                $textOverlay,
            );
        }

        $this->dispatch('toast', type: 'success', message: "Generated {$posters->count()} mockup(s).");
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

        GenerateMockup::dispatchSync(
            $posters,
            $template,
            $this->fitMode,
            $this->outputFormat,
            $this->outputQuality,
            $this->framePreset,
            $textOverlay,
        );

        $this->dispatch('toast', type: 'success', message: 'Generated multi-image mockup.');
    }

    public function generateAll(): void
    {
        $posters = Poster::whereIn('id', $this->selectedPosters)->get();
        $templates = MockupTemplate::query()
            ->when($this->categoryFilter, fn ($q) => $q->where('category', $this->categoryFilter))
            ->get();

        $textOverlay = $this->getTextOverlay();

        $count = 0;
        foreach ($posters as $poster) {
            foreach ($templates as $template) {
                GenerateMockup::dispatchSync(
                    $poster,
                    $template,
                    $this->fitMode,
                    $this->outputFormat,
                    $this->outputQuality,
                    $this->framePreset,
                    $textOverlay,
                );
                $count++;
            }
        }

        $this->dispatch('toast', type: 'success', message: "Generated {$count} mockup(s).");
    }

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
