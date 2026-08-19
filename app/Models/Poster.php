<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Poster extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'original_path',
        'upscaled_path',
        'style_category',
        'status',
        'metadata',
        'file_hash',
        'pushed_at',
        'feasible_sizes',
    ];

    protected $casts = [
        'metadata' => 'array',
        'pushed_at' => 'datetime',
        'feasible_sizes' => 'array',
    ];

    public function generatedMockups(): HasMany
    {
        return $this->hasMany(GeneratedMockup::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PosterActivity::class);
    }

    public function qcReports(): HasMany
    {
        return $this->hasMany(QcReport::class);
    }

    public function latestQcReport(): ?QcReport
    {
        return $this->qcReports()->orderByDesc('created_at')->first();
    }

    public static function createFromImport(string $path): ?static
    {
        $hash = md5_file($path);

        if ($hash && static::where('file_hash', $hash)->exists()) {
            return null;
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $title = Str::title(str_replace(['-', '_'], ' ', $filename));

        $poster = static::create([
            'title' => $title,
            'slug' => Str::slug($filename),
            'original_path' => $path,
            'status' => 'imported',
            'file_hash' => $hash ?: null,
        ]);

        // Haalbare printformaten direct bij import vastleggen; een
        // onleesbaar bestand mag de import zelf niet laten stranden.
        try {
            $poster->refreshFeasibleSizes();
        } catch (\Throwable $e) {
            \Log::warning('Kon haalbare formaten niet bepalen bij import', [
                'poster' => $poster->id,
                'fout' => $e->getMessage(),
            ]);
        }

        return $poster;
    }

    /**
     * Haalbare printformaten voor dit design (effectieve DPI na 4x
     * AI-upscale; zie DpiValidator::feasibilityFor). Bij import gevuld;
     * voor oudere posters wordt het hier alsnog berekend en bewaard.
     */
    public function feasibleSizes(): array
    {
        if ($this->feasible_sizes !== null) {
            return $this->feasible_sizes;
        }

        try {
            return $this->refreshFeasibleSizes();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Materiaal van dit ontwerp ('photo'|'illustration'). Bepaalt de
     * generatieve-factor-grens (foto strenger dan illustratie). Handmatig
     * gezet via style_category; ongelabeld = de shop-default. Bewust geen
     * automatische detectie — die bleek onbetrouwbaar op dit materiaal
     * (schilderkunstige illustraties meten niet vlakker dan foto's).
     */
    public function material(): string
    {
        return $this->style_category
            ?? (string) config('posterforge.upscale.default_material', 'illustration');
    }

    public function refreshFeasibleSizes(): array
    {
        $info = @getimagesize($this->original_path);
        if ($info === false) {
            return [];
        }

        $sizes = app(\App\Services\DpiValidator::class)
            ->feasibilityFor((int) $info[0], (int) $info[1], $this->material());

        $this->forceFill(['feasible_sizes' => $sizes])->save();

        return $sizes;
    }

    public function getDisplayImageAttribute(): string
    {
        return $this->upscaled_path ?? $this->original_path;
    }

    public function getThumbnailUrlAttribute(): string
    {
        if (file_exists($this->original_path)) {
            return route('poster.image', ['poster' => $this->id, 'type' => 'thumbnail']);
        }

        return '';
    }

    public function getOriginalUrlAttribute(): string
    {
        if (file_exists($this->original_path)) {
            return route('poster.image', ['poster' => $this->id, 'type' => 'original']);
        }

        return '';
    }

    public function getUpscaledUrlAttribute(): string
    {
        if ($this->upscaled_path && file_exists($this->upscaled_path)) {
            return route('poster.image', ['poster' => $this->id, 'type' => 'upscaled']);
        }

        return '';
    }
}
