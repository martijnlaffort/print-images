<?php

namespace App\Services;

use RuntimeException;

class ImageFinalizer
{
    public function __construct(
        private MagickService $magick,
    ) {}

    /**
     * Print-ready finalization: strip alpha (RGBA → RGB, geflattened op
     * wit — printpijplijnen struikelen over alfakanalen), embed the
     * configured ICC profile (as a real iCCP chunk — ImageMagick would
     * otherwise reduce sRGB to a bare sRGB chunk that print pipelines may
     * ignore) and stamp the true DPI.
     * Operates in place on the given file; never touches source files.
     */
    public function finalize(string $path, int $dpi = 300): string
    {
        $this->magick->run(
            [$path, ...$this->printReadyArgs($dpi), $path],
            config('posterforge.qc.magick_timeout', 300),
        );

        return $path;
    }

    /**
     * Schrijft één formaat-export: cover + center-crop naar de exacte
     * formaat-pixels plus de volledige print-klaar sanering. Een kale
     * "-resize WxH" past slechts bínnen de box en levert bij een
     * beeldverhouding die afwijkt van de master een verkeerd
     * printformaat op (bv. 35x50 in een "40x50"-bestand).
     */
    public function exportPrintFile(string $sourcePath, string $outputPath, int $width, int $height, int $dpi = 300): string
    {
        $this->magick->run([
            $sourcePath,
            '-filter', 'Lanczos',
            '-resize', "{$width}x{$height}^",
            '-gravity', 'center',
            '-extent', "{$width}x{$height}",
            ...$this->printReadyArgs($dpi),
            $outputPath,
        ], config('posterforge.qc.magick_timeout', 300));

        return $outputPath;
    }

    /**
     * De volledige print-klaar sanering als argumentenlijst, zodat élk
     * schrijfpad (finalisatie én size-variant exports) exact dezelfde
     * garanties geeft: alfa geflattened op wit (geen kanaal-drop, dus
     * geen halo's), DPI-metadata en het sRGB-profiel. ImageMagick
     * converteert netjes als er al een ander profiel in zit en kent het
     * profiel toe als er geen is — nooit dubbele profielen.
     */
    public function printReadyArgs(int $dpi = 300): array
    {
        return [
            '-background', 'white', '-alpha', 'remove', '-alpha', 'off',
            '-density', (string) $dpi, '-units', 'PixelsPerInch',
            ...$this->profileArgs(),
        ];
    }

    /**
     * Inline args to embed the ICC profile inside another magick command
     * (e.g. the export resize), avoiding a second pass over the file.
     */
    public function profileArgs(): array
    {
        if (! config('posterforge.icc.embed', true)) {
            return [];
        }

        return ['-profile', $this->profilePath(), '-define', 'png:preserve-iCCP=true'];
    }

    public function profilePath(): string
    {
        $configured = config('posterforge.icc.profile_path');
        $path = $configured ?: resource_path('icc/sRGB-IEC61966-2.1.icc');

        if (! file_exists($path)) {
            throw new RuntimeException("ICC profile not found: {$path}");
        }

        return $path;
    }
}
