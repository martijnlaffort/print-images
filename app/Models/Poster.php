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
    ];

    protected $casts = [
        'metadata' => 'array',
        'pushed_at' => 'datetime',
    ];

    public function generatedMockups(): HasMany
    {
        return $this->hasMany(GeneratedMockup::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PosterActivity::class);
    }

    public static function createFromImport(string $path): ?static
    {
        $hash = md5_file($path);

        if ($hash && static::where('file_hash', $hash)->exists()) {
            return null;
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $title = Str::title(str_replace(['-', '_'], ' ', $filename));

        return static::create([
            'title' => $title,
            'slug' => Str::slug($filename),
            'original_path' => $path,
            'status' => 'imported',
            'file_hash' => $hash ?: null,
        ]);
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
