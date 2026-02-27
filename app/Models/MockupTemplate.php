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
}
