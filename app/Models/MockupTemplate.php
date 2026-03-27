<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MockupTemplate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'category',
        'background_path',
        'shadow_path',
        'frame_path',
        'corners',
        'brightness_adjust',
        'aspect_ratio',
    ];

    protected $casts = [
        'corners' => 'array',
        'brightness_adjust' => 'integer',
    ];

    public function generatedMockups(): HasMany
    {
        return $this->hasMany(GeneratedMockup::class, 'template_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(TemplateSlot::class, 'template_id')->orderBy('sort_order');
    }

    /**
     * Get all placement areas: slots if any exist, otherwise fall back to the legacy corners field.
     */
    public function getAllSlots(): array
    {
        $slots = $this->slots;

        if ($slots->isNotEmpty()) {
            return $slots->map(fn ($s) => [
                'label' => $s->label,
                'corners' => $s->corners,
                'aspect_ratio' => $s->aspect_ratio,
            ])->toArray();
        }

        return [[
            'label' => 'Main',
            'corners' => $this->corners,
            'aspect_ratio' => $this->aspect_ratio,
        ]];
    }
}
