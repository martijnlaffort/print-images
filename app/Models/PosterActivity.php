<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosterActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'poster_id',
        'action',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function poster(): BelongsTo
    {
        return $this->belongsTo(Poster::class);
    }

    public static function log(int $posterId, string $action, ?array $details = null): static
    {
        return static::create([
            'poster_id' => $posterId,
            'action' => $action,
            'details' => $details,
        ]);
    }
}
