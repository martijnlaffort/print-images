<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Services\DpiValidator;
use Livewire\Component;

class Settings extends Component
{
    // Export defaults
    public string $defaultDir = '';

    // Naming patterns
    public string $namingUpscaled = '';
    public string $namingSize = '';
    public string $namingMockup = '';

    // Custom sizes
    public array $customSizes = [];
    public string $newSizeName = '';
    public string $newSizeWidth = '';
    public string $newSizeHeight = '';

    public function mount(): void
    {
        $this->defaultDir = Setting::get('export.default_dir', storage_path('app/exports'));
        $this->namingUpscaled = Setting::get('naming.upscaled', config('posterforge.naming.upscaled', '{title}_upscaled.png'));
        $this->namingSize = Setting::get('naming.size_variant', config('posterforge.naming.size_variant', '{title}_{size}.png'));
        $this->namingMockup = Setting::get('naming.mockup', config('posterforge.naming.mockup', '{title}_mockup_{template}.jpg'));
        $this->customSizes = Setting::get('print_sizes', []);
    }

    public function selectDir(): void
    {
        try {
            $dir = \Native\Laravel\Dialog::new()
                ->title('Select default export folder')
                ->folders()
                ->open();

            if ($dir) {
                $this->defaultDir = $dir;
            }
        } catch (\Throwable) {
            // Dialog not available outside Electron
        }
    }

    public function saveExportDefaults(): void
    {
        Setting::set('export.default_dir', $this->defaultDir);
        $this->dispatch('toast', type: 'success', message: 'Export defaults saved.');
    }

    public function saveNamingPatterns(): void
    {
        Setting::set('naming.upscaled', $this->namingUpscaled);
        Setting::set('naming.size_variant', $this->namingSize);
        Setting::set('naming.mockup', $this->namingMockup);
        $this->dispatch('toast', type: 'success', message: 'Naming patterns saved.');
    }

    public function addCustomSize(): void
    {
        $this->validate([
            'newSizeName' => 'required|string|max:50',
            'newSizeWidth' => 'required|numeric|min:1',
            'newSizeHeight' => 'required|numeric|min:1',
        ]);

        $this->customSizes[] = [
            'name' => $this->newSizeName,
            'width_cm' => (float) $this->newSizeWidth,
            'height_cm' => (float) $this->newSizeHeight,
        ];

        Setting::set('print_sizes', $this->customSizes);

        $this->newSizeName = '';
        $this->newSizeWidth = '';
        $this->newSizeHeight = '';

        $this->dispatch('toast', type: 'success', message: 'Custom size added.');
    }

    public function removeCustomSize(int $index): void
    {
        unset($this->customSizes[$index]);
        $this->customSizes = array_values($this->customSizes);
        Setting::set('print_sizes', $this->customSizes);
        $this->dispatch('toast', type: 'success', message: 'Custom size removed.');
    }

    public function render()
    {
        return view('livewire.settings', [
            'builtInSizes' => DpiValidator::SIZES,
        ]);
    }
}
