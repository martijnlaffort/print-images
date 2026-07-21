<?php

namespace App\Services\Benchmark;

use App\Services\DenoiseService;
use App\Services\ImageFinalizer;
use App\Services\MagickService;
use App\Services\UpscaleService;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * De losse pipeline-stappen van één upscale-variant, herbruikbaar door
 * de benchmark (volledige beelden) en de autotune (mini-crops):
 * pre-denoise → AI-pass → optionele bicubic-blend → render naar doelbox.
 * Stappen cachen op bestandspad; bronnen worden nooit aangeraakt.
 */
class VariantProcessor
{
    public function __construct(
        private MagickService $magick,
        private UpscaleService $upscale,
        private DenoiseService $denoise,
        private ImageFinalizer $finalizer,
    ) {}

    public function aiScaleFor(float $requiredScale): int
    {
        return $requiredScale <= 2.0 ? 2 : 4;
    }

    /** Wavelet pre-denoise ('off' geeft de bron ongewijzigd terug). */
    public function preDenoise(string $source, string $cachePath, string $strength): string
    {
        if ($strength === 'off') {
            return $source;
        }

        if (! file_exists($cachePath)) {
            $this->denoise->denoise($source, $cachePath, $strength);
        }

        return $cachePath;
    }

    /** Real-ESRGAN-pass; retourneert de duur in seconden (0.0 bij cache-hit). */
    public function aiPass(string $input, string $output, string $model, int $scale): float
    {
        if (file_exists($output)) {
            return 0.0;
        }

        $start = microtime(true);

        $result = Process::timeout(3600)->run([
            $this->upscale->getBinaryPath(),
            '-i', $input,
            '-o', $output,
            '-s', (string) $scale,
            '-n', $model,
            '-f', 'png',
        ]);

        if ($result->failed() || ! file_exists($output)) {
            throw new RuntimeException("AI-pass mislukt ({$model}): " . $result->errorOutput());
        }

        return round(microtime(true) - $start, 1);
    }

    /**
     * Dissolve-blend van bicubic over de AI-output (de oude "denoise"-knop,
     * als expliciete benchmark-as). Cachet de bicubic-vergroting apart.
     */
    public function blend(string $preInput, string $aiPath, int $blendPct, int $aiScale, string $bicubicCache, string $outPath): string
    {
        if ($blendPct <= 0) {
            return $aiPath;
        }

        if (! file_exists($bicubicCache)) {
            $percent = $aiScale * 100;
            $this->magick->run([
                $preInput, '-filter', (string) config('posterforge.benchmark.baseline_filter', 'Catrom'),
                '-resize', "{$percent}%", $bicubicCache,
            ], $this->timeout());
        }

        if (! file_exists($outPath)) {
            $aiWeight = 100 - $blendPct;
            $this->magick->run([
                'composite', '-dissolve', (string) $aiWeight, $aiPath, $bicubicCache, $outPath,
            ], $this->timeout());
        }

        return $outPath;
    }

    /**
     * Naar de exacte doelbox: alfa strippen (RGBA → RGB op wit),
     * cover-resize + center-crop (geen aspect-vervorming), optioneel
     * post-sharpen, DPI + sRGB-profiel. Output is altijd PNG.
     */
    public function renderToTarget(string $input, string $output, array $box, int $dpi, string $filter, int $sharpen): void
    {
        $W = $box['width'];
        $H = $box['height'];

        $args = [
            $input . '[0]',
            '-background', 'white', '-alpha', 'remove', '-alpha', 'off',
            '-filter', $filter,
            '-resize', "{$W}x{$H}^",
            '-gravity', 'center', '-extent', "{$W}x{$H}",
        ];

        if ($sharpen > 0) {
            $sigma = round(0.5 + ($sharpen / 100) * 1.0, 2);
            $gain = round(0.5 + ($sharpen / 100) * 1.0, 2);
            $args = array_merge($args, ['-unsharp', "0x{$sigma}+{$gain}+0.02"]);
        }

        $args = array_merge($args, [
            '-density', (string) $dpi, '-units', 'PixelsPerInch',
        ], $this->finalizer->profileArgs(), [$output]);

        $this->magick->run($args, $this->timeout());
    }

    private function timeout(): int
    {
        return (int) config('posterforge.benchmark.magick_timeout', 600);
    }
}
