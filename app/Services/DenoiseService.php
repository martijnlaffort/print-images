<?php

namespace App\Services;

use InvalidArgumentException;

class DenoiseService
{
    public function __construct(
        private MagickService $magick,
    ) {}

    /**
     * Wavelet denoise: removes high-frequency grain while preserving edges.
     * Runs before any upscaling. Input and output are always PNG — no JPEG
     * anywhere in the print chain.
     */
    public function denoise(string $inputPath, string $outputPath, string $strength = 'normal'): string
    {
        $threshold = $this->threshold($strength);

        if (! str_ends_with(strtolower($outputPath), '.png')) {
            throw new InvalidArgumentException('Denoise output must be PNG: ' . $outputPath);
        }

        $this->magick->run([
            $inputPath,
            '-wavelet-denoise', "{$threshold}%",
            $outputPath,
        ], config('posterforge.qc.magick_timeout', 300));

        return $outputPath;
    }

    public function threshold(string $strength): float
    {
        $strengths = config('posterforge.denoise.strengths', []);

        if (! isset($strengths[$strength])) {
            throw new InvalidArgumentException("Unknown denoise strength: {$strength}");
        }

        return (float) $strengths[$strength];
    }

    public static function strengthOptions(): array
    {
        return [
            'light' => 'Licht',
            'normal' => 'Normaal',
            'strong' => 'Sterk',
        ];
    }
}
