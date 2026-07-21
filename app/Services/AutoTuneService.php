<?php

namespace App\Services;

use App\Services\Benchmark\BenchmarkMetrics;
use App\Services\Benchmark\BenchmarkScorer;
use App\Services\Benchmark\VariantProcessor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Automatische configuratie-keuze per afbeelding: een mini-benchmark op
 * de detailrijkste uitsnede kiest uit de geconfigureerde kandidaten de
 * best scorende upscale-configuratie, vóórdat de dure volledige run
 * start. Daarnaast: eerlijke formaat-gating op effectieve DPI.
 */
class AutoTuneService
{
    public function __construct(
        private MagickService $magick,
        private DpiValidator $dpiValidator,
        private BenchmarkMetrics $metrics,
        private VariantProcessor $processor,
        private BenchmarkScorer $scorer,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('posterforge.autotune.enabled', false);
    }

    /**
     * Formaat-gating vóór de dure upscale: haalbaar = effectieve DPI na
     * één 4x AI-pass boven de configureerbare ondergrens. Geen enkele
     * instelling kan ontbrekende bronpixels compenseren.
     */
    public function gate(string $sourcePath, string $targetSize): array
    {
        [$w, $h] = $this->dimensions($sourcePath);
        $minDpi = (int) config('posterforge.autotune.min_dpi', 200);

        $dpi = $this->dpiValidator->effectiveDpiFor($w * 4, $h * 4, $targetSize);
        if (! $dpi) {
            throw new RuntimeException("Onbekend printformaat: {$targetSize}");
        }

        // qc.sizes staat van klein naar groot; de laatste haalbare wint.
        $maxSellable = null;
        foreach (config('posterforge.qc.sizes', []) as $size) {
            $row = $this->dpiValidator->effectiveDpiFor($w * 4, $h * 4, $size);
            if ($row && $row['min_dpi'] >= $minDpi) {
                $maxSellable = $size;
            }
        }

        return [
            'feasible' => $dpi['min_dpi'] >= $minDpi,
            'effective_dpi' => $dpi['min_dpi'],
            'min_dpi' => $minDpi,
            'max_sellable_size' => $maxSellable,
        ];
    }

    /**
     * Mini-benchmark: draait de kandidaat-configuraties op de detailrijkste
     * uitsnede van de bron en retourneert de winnaar met onderbouwing.
     */
    public function choose(string $sourcePath, string $targetSize, int $targetDpi, ?\Closure $onProgress = null): array
    {
        $say = fn (string $msg) => $onProgress ? $onProgress($msg) : null;

        $cfgA = config('posterforge.autotune');
        $cfgB = config('posterforge.benchmark');
        $candidates = $cfgA['candidates'] ?? [];
        if (! $candidates) {
            throw new RuntimeException('Geen autotune-kandidaten geconfigureerd.');
        }

        [$w, $h] = $this->dimensions($sourcePath);
        $box = $this->dpiValidator->pixelsAtDpi($targetSize, $targetDpi);
        if (! $box) {
            throw new RuntimeException("Onbekend printformaat: {$targetSize}");
        }
        $F = max($box['width'] / $w, $box['height'] / $h);

        $block = (int) $cfgB['blocks']['size'];
        $cropSrc = (int) min($cfgA['crop_size'], $w, $h);
        if ($cropSrc < $block * 2) {
            throw new RuntimeException('Bron te klein voor autotune-crop.');
        }

        $slug = Str::slug(pathinfo($sourcePath, PATHINFO_FILENAME));
        $workDir = storage_path('app' . DIRECTORY_SEPARATOR . 'autotune' . DIRECTORY_SEPARATOR . $slug);
        File::deleteDirectory($workDir);
        foreach (['cache', 'variants'] as $sub) {
            mkdir($workDir . DIRECTORY_SEPARATOR . $sub, 0755, true);
        }
        $cache = $workDir . DIRECTORY_SEPARATOR . 'cache';

        // ── Detailrijkste uitsnede van de bron ──
        $say('Detailrijkste uitsnede zoeken...');
        $map = $this->metrics->tileMap($sourcePath, $block);
        $center = $this->metrics->bestWindowCenter($map, $cropSrc, richest: true);
        $cx = max(0, min($w - $cropSrc, $center['x'] - intdiv($cropSrc, 2)));
        $cy = max(0, min($h - $cropSrc, $center['y'] - intdiv($cropSrc, 2)));

        $cropPath = $workDir . DIRECTORY_SEPARATOR . 'crop.png';
        $this->magick->run([
            $sourcePath . '[0]', '-crop', "{$cropSrc}x{$cropSrc}+{$cx}+{$cy}", '+repage', $cropPath,
        ], (int) $cfgB['magick_timeout']);

        // ── Doelbox voor de crop (zelfde vergrotingsfactor als het volle beeld) ──
        $side = (int) round($cropSrc * $F);
        $cropBox = ['width' => $side, 'height' => $side];
        $Fc = $side / $cropSrc;

        // ── Meetblokken binnen de crop ──
        $cropMap = $this->metrics->tileMap($cropPath, $block);
        $detailRects = $this->mapCropTiles($this->metrics->detailTiles($cropMap, $cfgA['blocks']['detail_count'] * 2), $block, $Fc, $side, (int) $cfgA['blocks']['detail_count']);
        $edgeRects = $this->mapCropTiles($this->metrics->edgeTiles($cropMap, $cfgA['blocks']['edge_count'] * 2), $block, $Fc, $side, (int) $cfgA['blocks']['edge_count']);

        // ── Baseline ──
        $say('Bicubic-baseline op de uitsnede meten...');
        $baselinePath = $workDir . DIRECTORY_SEPARATOR . 'baseline.png';
        $this->processor->renderToTarget($cropPath, $baselinePath, $cropBox, $targetDpi, (string) $cfgB['baseline_filter'], 0);
        $baseline = $this->measure($baselinePath, $detailRects, $edgeRects, $cfgA);

        // ── Kandidaten draaien (AI-passes gededuped via cache-paden) ──
        $aiScale = $this->processor->aiScaleFor($F);
        $weights = $cfgA['weights'] ?: null;
        $ranked = [];

        foreach ($candidates as $i => $candidate) {
            $pre = $candidate['pre_denoise'];
            $model = $candidate['model'];
            $blend = (int) $candidate['blend_bicubic'];
            $sharpen = (int) $candidate['sharpen'];

            $say('Kandidaat ' . ($i + 1) . '/' . count($candidates) . ": {$model} (pre={$pre}, blend={$blend}, sharpen={$sharpen})...");

            $preInput = $this->processor->preDenoise($cropPath, $cache . DIRECTORY_SEPARATOR . "pre_{$pre}.png", $pre);
            $aiPath = $cache . DIRECTORY_SEPARATOR . "ai_{$pre}_{$model}_s{$aiScale}.png";
            $this->processor->aiPass($preInput, $aiPath, $model, $aiScale);

            $postInput = $this->processor->blend(
                $preInput, $aiPath, $blend, $aiScale,
                $cache . DIRECTORY_SEPARATOR . "bicubic_{$pre}_s{$aiScale}.png",
                $cache . DIRECTORY_SEPARATOR . "blend_{$pre}_{$model}_b{$blend}.png",
            );

            $variantPath = $workDir . DIRECTORY_SEPARATOR . 'variants' . DIRECTORY_SEPARATOR . "cand_{$i}.png";
            $this->processor->renderToTarget($postInput, $variantPath, $cropBox, $targetDpi, 'Lanczos', $sharpen);

            $m = $this->measure($variantPath, $detailRects, $edgeRects, $cfgA);
            $ratios = $this->scorer->ratios($m, $baseline);

            $ranked[] = [
                'config' => $candidate,
                'metrics' => $m,
                'ratios' => $ratios,
                'scores' => $this->scorer->score($ratios, $m, $weights),
                'path' => $variantPath,
            ];
        }

        usort($ranked, fn ($a, $b) => $b['scores']['total'] <=> $a['scores']['total']);
        $winner = $ranked[0];

        // Schijf-zuinig: alleen de kleine bron-crop blijft achter ter inspectie;
        // de keuze zelf wordt volledig gelogd in de poster-activiteit.
        if (! (bool) config('posterforge.benchmark.keep_files', false)) {
            File::deleteDirectory($cache);
            File::deleteDirectory($workDir . DIRECTORY_SEPARATOR . 'variants');
            @unlink($baselinePath);
        }

        return [
            'config' => $winner['config'],
            'score' => $winner['scores']['total'],
            'scores' => $winner['scores'],
            'ratios' => $winner['ratios'],
            'candidates' => array_map(fn ($r) => [
                'config' => $r['config'],
                'score' => $r['scores']['total'],
                'detail_ratio' => $r['ratios']['detail'],
                'noise_sd' => $r['metrics']['noise_sd'],
            ], $ranked),
            'crop' => ['x' => $cx, 'y' => $cy, 'size' => $cropSrc],
            'work_dir' => $workDir,
        ];
    }

    private function measure(string $path, array $detailRects, array $edgeRects, array $cfgA): array
    {
        return [
            'noise_sd' => $this->metrics->noiseSd($path, 64, (int) $cfgA['blocks']['noise_count']),
            'sharpness' => $this->metrics->laplacianSd($path),
            'detail' => $this->metrics->rectLaplacian($path, $detailRects),
            'texture' => $this->metrics->rectTexture($path, $detailRects),
            'edges' => $this->metrics->rectSobelEnergy($path, $edgeRects),
        ];
    }

    /** Crop-tiles naar doel-rects (vierkante crop → vierkante box, geen offsets). */
    private function mapCropTiles(array $tiles, int $block, float $Fc, int $side, int $count): array
    {
        $size = (int) round($block * $Fc);
        $rects = [];

        foreach ($tiles as $t) {
            $x = (int) round($t['tx'] * $block * $Fc);
            $y = (int) round($t['ty'] * $block * $Fc);
            if ($x < 0 || $y < 0 || $x + $size > $side || $y + $size > $side) {
                continue;
            }
            $rects[] = ['x' => $x, 'y' => $y, 'w' => $size, 'h' => $size];
            if (count($rects) >= $count) {
                break;
            }
        }

        if (count($rects) < 3) {
            throw new RuntimeException('Te weinig meetblokken binnen de autotune-crop.');
        }

        return $rects;
    }

    private function dimensions(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException("Kan afmetingen niet lezen: {$path}");
        }

        return [(int) $info[0], (int) $info[1]];
    }
}
