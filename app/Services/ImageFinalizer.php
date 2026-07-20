<?php

namespace App\Services;

use RuntimeException;

class ImageFinalizer
{
    public function __construct(
        private MagickService $magick,
    ) {}

    /**
     * Print-ready finalization: embed the configured ICC profile (as a real
     * iCCP chunk — ImageMagick would otherwise reduce sRGB to a bare sRGB
     * chunk that print pipelines may ignore) and stamp the true DPI.
     * Operates in place on the given file; never touches source files.
     */
    public function finalize(string $path, int $dpi = 300): string
    {
        $args = [$path, '-density', (string) $dpi, '-units', 'PixelsPerInch'];

        if (config('posterforge.icc.embed', true)) {
            $args[] = '-profile';
            $args[] = $this->profilePath();
            $args[] = '-define';
            $args[] = 'png:preserve-iCCP=true';
        }

        $args[] = $path;

        $this->magick->run($args, config('posterforge.qc.magick_timeout', 300));

        return $path;
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
