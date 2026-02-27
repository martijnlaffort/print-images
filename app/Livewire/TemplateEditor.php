<?php

namespace App\Livewire;

use App\Models\MockupTemplate;
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
        'corners' => 'required|array|size:4',
        'corners.*.x' => 'required|numeric',
        'corners.*.y' => 'required|numeric',
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
            $this->corners = $template->corners;
        }
    }

    public function updateCorner(int $index, float $x, float $y): void
    {
        $this->corners[$index] = ['x' => round($x), 'y' => round($y)];
    }

    public function saveTemplate(): void
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'category' => $this->category,
            'corners' => $this->corners,
            'brightness_adjust' => $this->brightnessAdjust,
            'aspect_ratio' => $this->aspectRatio,
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
            MockupTemplate::create($data);
        }

        $this->redirect('/templates', navigate: true);
    }

    public function getTemplateProperty(): ?MockupTemplate
    {
        return $this->templateId ? MockupTemplate::find($this->templateId) : null;
    }

    public function render()
    {
        return view('livewire.template-editor', [
            'template' => $this->template,
        ]);
    }
}
