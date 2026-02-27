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
        $path = $this->original_path;

        if (file_exists($path)) {
            return 'data:image/jpeg;base64,' . base64_encode(file_get_contents($path));
        }

        return '';
    }
}
