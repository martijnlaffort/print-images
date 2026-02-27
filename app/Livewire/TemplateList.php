<?php

namespace App\Livewire;

use App\Models\MockupTemplate;
use Livewire\Component;

class TemplateList extends Component
{
    public function deleteTemplate(int $id): void
    {
        MockupTemplate::findOrFail($id)->delete();
    }

    public function render()
    {
        return view('livewire.template-list', [
            'templates' => MockupTemplate::orderBy('name')->get(),
        ]);
    }
}
