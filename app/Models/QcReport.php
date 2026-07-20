<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcReport extends Model
{
    protected $fillable = [
        'poster_id',
        'source_path',
        'phase',
        'verdict',
        'metrics',
        'reasons',
        'comparison',
        'comparison_image_path',
        'batch_id',
    ];

    protected $casts = [
        'metrics' => 'array',
        'reasons' => 'array',
        'comparison' => 'array',
    ];

    public function poster(): BelongsTo
    {
        return $this->belongsTo(Poster::class);
    }

    public function verdictLabel(): string
    {
        return match ($this->verdict) {
            'pass' => 'PRINT-KLAAR',
            'warn' => 'WAARSCHUWING',
            'fail' => 'NIET PRINTEN',
            default => strtoupper($this->verdict),
        };
    }
}
