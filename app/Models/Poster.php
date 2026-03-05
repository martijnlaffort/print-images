<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Poster extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'original_path',
        'upscaled_path',
        'style_category',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function generatedMockups(): HasMany
    {
        return $this->hasMany(GeneratedMockup::class);
    }

    public static function createFromImport(string $path): static
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $title = Str::title(str_replace(['-', '_'], ' ', $filename));

        return static::create([
            'title' => $title,
            'slug' => Str::slug($filename),
            'original_path' => $path,
            'status' => 'imported',
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
