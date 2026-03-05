<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateSlot extends Model
{
    protected $fillable = [
        'template_id',
        'label',
        'corners',
        'aspect_ratio',
        'sort_order',
    ];

    protected $casts = [
        'corners' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MockupTemplate::class, 'template_id');
    }
}
