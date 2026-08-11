<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén geëxporteerd printbestand met zijn printqc-uitslag. "Klaar voor
 * Gelato" is uitsluitend status 'released'; 'review' is handmatig
 * beoordelen, 'blocked' is hernoemd naar *_GEBLOKKEERD.png.
 */
class ExportFile extends Model
{
    protected $fillable = [
        'poster_id',
        'size',
        'path',
        'status',
        'printqc_exit',
        'printqc_status',
        'findings',
        'meta',
        'json_path',
        'report_dir',
        'md5',
    ];

    protected $casts = [
        'findings' => 'array',
        'meta' => 'array',
    ];

    public function poster(): BelongsTo
    {
        return $this->belongsTo(Poster::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'released' => 'VRIJGEGEVEN',
            'review' => 'HANDMATIG BEOORDELEN',
            'blocked' => 'GEBLOKKEERD',
            default => strtoupper($this->status),
        };
    }

    /**
     * Crop-bestanden uit het printqc-rapport; index 0 is de
     * overzichtskaart met de verdachte plekken rood gemarkeerd.
     * Bewust ongefilterd: de indexen moeten stabiel blijven tussen
     * paginaweergave en de export.crop-route (verdwenen bestanden
     * geven daar netjes een 404 in plaats van de verkeerde crop).
     */
    public function crops(): array
    {
        return array_values($this->meta['crops'] ?? []);
    }
}
