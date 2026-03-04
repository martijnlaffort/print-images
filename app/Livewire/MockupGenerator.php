<?php

namespace App\Livewire;

use App\Jobs\GenerateMockup;
use App\Models\GeneratedMockup;
use App\Models\MockupTemplate;
use App\Models\Poster;
use Livewire\Component;

class MockupGenerator extends Component
{
    public array $selectedPosters = [];
    public ?int $selectedTemplate = null;
    public string $categoryFilter = '';

    public function generateForTemplate(int $templateId): void
    {
        $template = MockupTemplate::findOrFail($templateId);
        $posters = Poster::whereIn('id', $this->selectedPosters)->get();

        foreach ($posters as $poster) {
            GenerateMockup::dispatchSync($poster, $template);
        }

        $this->dispatch('toast', type: 'success', message: "Generated {$posters->count()} mockup(s).");
    }

    public function generateAll(): void
    {
        $posters = Poster::whereIn('id', $this->selectedPosters)->get();
        $templates = MockupTemplate::query()
            ->when($this->categoryFilter, fn ($q) => $q->where('category', $this->categoryFilter))
            ->get();

        $count = 0;
        foreach ($posters as $poster) {
            foreach ($templates as $template) {
                GenerateMockup::dispatchSync($poster, $template);
                $count++;
            }
        }

        $this->dispatch('toast', type: 'success', message: "Generated {$count} mockup(s).");
    }

    public function selectTemplate(int $id): void
    {
        $this->selectedTemplate = $id;
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

    public function render()
    {
        return view('livewire.mockup-generator', [
            'posters' => $this->posters,
            'templates' => $this->templates,
            'categories' => $this->categories,
            'mockups' => $this->mockups,
        ]);
    }
}
