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

    /**
     * Details plat en veilig voor weergave: geneste arrays (bv. de
     * autotune-kandidatenlijst) worden compacte JSON en lange waardes
     * afgekapt, zodat de activiteiten-feed nooit op een array stukloopt.
     */
    public function formattedDetails(): array
    {
        return collect($this->details ?? [])->map(function ($value) {
            if (is_array($value)) {
                $value = collect($value)
                    ->map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v)
                    ->implode(', ');
            }

            return \Illuminate\Support\Str::limit((string) $value, 120);
        })->all();
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
