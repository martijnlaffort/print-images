<?php

namespace App\Livewire;

use App\Models\MockupTemplate;
use App\Models\Poster;
use App\Models\TemplateSlot;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class TemplateEditor extends Component
{
    use WithFileUploads;

    public ?int $templateId = null;
    public $backgroundImage;
    public $shadowImage;
    public $frameImage;
    public string $name = '';
    public string $category = 'living-room';
    public int $brightnessAdjust = 100;
    public string $aspectRatio = 'portrait';
    public ?int $samplePosterId = null;

    // Multi-slot support
    public array $posterSlots = [];
    public int $activeSlot = 0;

    // Legacy single corners (used for active slot)
    public array $corners = [
        ['x' => 200, 'y' => 100],
        ['x' => 500, 'y' => 100],
        ['x' => 500, 'y' => 450],
        ['x' => 200, 'y' => 450],
    ];

    protected $rules = [
        'name' => 'required|string|max:255',
        'category' => 'required|string',
        'backgroundImage' => 'nullable|image|max:20480',
    ];

    public function mount(?int $id = null): void
    {
        if ($id) {
            $template = MockupTemplate::findOrFail($id);
            $this->templateId = $template->id;
            $this->name = $template->name;
            $this->category = $template->category;
            $this->brightnessAdjust = $template->brightness_adjust;
            $this->aspectRatio = $template->aspect_ratio;

            // Load slots
            $allSlots = $template->getAllSlots();
            $this->posterSlots = $allSlots;
            $this->corners = $allSlots[0]['corners'] ?? $this->corners;
        } else {
            $this->posterSlots = [[
                'label' => 'Main',
                'corners' => $this->corners,
                'aspect_ratio' => 'portrait',
            ]];
        }

        $firstPoster = Poster::whereNotNull('original_path')->first();
        if ($firstPoster) {
            $this->samplePosterId = $firstPoster->id;
        }
    }

    public function updateCorner(int $index, float $x, float $y): void
    {
        $this->corners[$index] = ['x' => round($x), 'y' => round($y)];
        $this->posterSlots[$this->activeSlot]['corners'] = $this->corners;
    }

    public function switchSlot(int $index): void
    {
        // Save current corners to current slot
        $this->posterSlots[$this->activeSlot]['corners'] = $this->corners;
        $this->activeSlot = $index;
        $this->corners = $this->posterSlots[$index]['corners'];
    }

    public function addSlot(): void
    {
        // Save current
        $this->posterSlots[$this->activeSlot]['corners'] = $this->corners;

        $this->posterSlots[] = [
            'label' => 'Poster ' . (count($this->posterSlots) + 1),
            'corners' => [
                ['x' => 600, 'y' => 100],
                ['x' => 900, 'y' => 100],
                ['x' => 900, 'y' => 450],
                ['x' => 600, 'y' => 450],
            ],
            'aspect_ratio' => 'portrait',
        ];

        $this->activeSlot = count($this->posterSlots) - 1;
        $this->corners = $this->posterSlots[$this->activeSlot]['corners'];
    }

    public function removeSlot(int $index): void
    {
        if (count($this->posterSlots) <= 1) {
            return;
        }

        array_splice($this->posterSlots, $index, 1);
        $this->activeSlot = min($this->activeSlot, count($this->posterSlots) - 1);
        $this->corners = $this->posterSlots[$this->activeSlot]['corners'];
    }

    public function updateSlotLabel(int $index, string $label): void
    {
        $this->posterSlots[$index]['label'] = $label;
    }

    public function applyPreset(string $preset): void
    {
        $presets = [
            'centered' => [
                ['x' => 0.25, 'y' => 0.15],
                ['x' => 0.75, 'y' => 0.15],
                ['x' => 0.75, 'y' => 0.75],
                ['x' => 0.25, 'y' => 0.75],
            ],
            'centered-large' => [
                ['x' => 0.15, 'y' => 0.08],
                ['x' => 0.85, 'y' => 0.08],
                ['x' => 0.85, 'y' => 0.85],
                ['x' => 0.15, 'y' => 0.85],
            ],
            'angled-left' => [
                ['x' => 0.20, 'y' => 0.18],
                ['x' => 0.68, 'y' => 0.12],
                ['x' => 0.70, 'y' => 0.78],
                ['x' => 0.22, 'y' => 0.72],
            ],
            'angled-right' => [
                ['x' => 0.32, 'y' => 0.12],
                ['x' => 0.80, 'y' => 0.18],
                ['x' => 0.78, 'y' => 0.72],
                ['x' => 0.30, 'y' => 0.78],
            ],
            'above-sofa' => [
                ['x' => 0.28, 'y' => 0.05],
                ['x' => 0.72, 'y' => 0.05],
                ['x' => 0.72, 'y' => 0.52],
                ['x' => 0.28, 'y' => 0.52],
            ],
        ];

        $this->dispatch('apply-preset', corners: $presets[$preset] ?? $presets['centered']);
    }

    public function saveTemplate(): void
    {
        $this->validate();

        // Save current corners to active slot
        $this->posterSlots[$this->activeSlot]['corners'] = $this->corners;

        $data = [
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'category' => $this->category,
            'brightness_adjust' => $this->brightnessAdjust,
            'aspect_ratio' => $this->posterSlots[0]['aspect_ratio'] ?? $this->aspectRatio,
            'corners' => $this->posterSlots[0]['corners'] ?? $this->corners,
        ];

        if ($this->backgroundImage) {
            $storagePath = storage_path('app/templates');
            if (! is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $bgName = Str::slug($this->name) . '-bg.' . $this->backgroundImage->getClientOriginalExtension();
            $bgPath = $storagePath . '/' . $bgName;
            copy($this->backgroundImage->getRealPath(), $bgPath);
            $data['background_path'] = $bgPath;
        }

        if ($this->shadowImage) {
            $storagePath = storage_path('app/templates');
            $shadowName = Str::slug($this->name) . '-shadow.png';
            $shadowPath = $storagePath . '/' . $shadowName;
            copy($this->shadowImage->getRealPath(), $shadowPath);
            $data['shadow_path'] = $shadowPath;
        }

        if ($this->frameImage) {
            $storagePath = storage_path('app/templates');
            $frameName = Str::slug($this->name) . '-frame.png';
            $framePath = $storagePath . '/' . $frameName;
            copy($this->frameImage->getRealPath(), $framePath);
            $data['frame_path'] = $framePath;
        }

        if ($this->templateId) {
            $template = MockupTemplate::findOrFail($this->templateId);
            $template->update($data);
        } else {
            if (! isset($data['background_path'])) {
                $this->addError('backgroundImage', 'A background image is required for new templates.');
                return;
            }
            $template = MockupTemplate::create($data);
            $this->templateId = $template->id;
        }

        // Save slots
        TemplateSlot::where('template_id', $template->id)->delete();
        foreach ($this->posterSlots as $i => $slot) {
            TemplateSlot::create([
                'template_id' => $template->id,
                'label' => $slot['label'],
                'corners' => $slot['corners'],
                'aspect_ratio' => $slot['aspect_ratio'] ?? 'portrait',
                'sort_order' => $i,
            ]);
        }

        $this->dispatch('toast', type: 'success', message: 'Template saved.');
        $this->redirect('/templates', navigate: true);
    }

    public function getTemplateProperty(): ?MockupTemplate
    {
        return $this->templateId ? MockupTemplate::find($this->templateId) : null;
    }

    public function getSamplePostersProperty()
    {
        return Poster::whereNotNull('original_path')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    public function getSamplePosterImageProperty(): ?string
    {
        if (! $this->samplePosterId) {
            return null;
        }

        $poster = Poster::find($this->samplePosterId);
        if (! $poster || ! file_exists($poster->display_image)) {
            return null;
        }

        $ext = strtolower(pathinfo($poster->display_image, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return "data:{$mime};base64," . base64_encode(file_get_contents($poster->display_image));
    }

    public function render()
    {
        return view('livewire.template-editor', [
            'template' => $this->template,
            'samplePosters' => $this->samplePosters,
            'samplePosterImage' => $this->samplePosterImage,
        ]);
    }
}
