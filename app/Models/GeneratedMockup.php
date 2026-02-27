<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedMockup extends Model
{
    protected $fillable = [
        'poster_id',
        'template_id',
        'output_path',
    ];

    public function poster(): BelongsTo
    {
        return $this->belongsTo(Poster::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MockupTemplate::class, 'template_id');
    }
}
