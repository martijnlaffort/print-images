<?php

namespace App\Services\Benchmark;

use App\Services\DpiValidator;
use App\Services\MagickService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Draait de benchmark-matrix: per bron × configuratie één output op het
 * doelformaat, objectief gemeten t.o.v. een bicubic-baseline. Dure
 * AI-passes worden gecached; blend/sharpen zijn nabewerkingen daarop.
 * Bronbestanden worden nooit aangeraakt; alles is PNG.
 */
class BenchmarkRunner
{
    public function __construct(
        private DpiValidator $dpiValidator,
        private BenchmarkMetrics $metrics,
        private VariantProcessor $processor,
        private BenchmarkScorer $scorer,
        private MagickService $magick,
    ) {}

    /**
     * Vooraf-melding: aantal AI-passes, varianten en een ruwe tijdschatting.
     */
    public function plan(array $sources, array $axes, string $targetSize, int $targetDpi): array
    {
        $aiPassesPerSource = count($axes['pre_denoise']) * count($axes['model']);
        $variantsPerSource = $aiPassesPerSource * count($axes['blend_bicubic']) * count($axes['sharpen']);

        $secPerMp = (float) config('posterforge.benchmark.ai_seconds_per_megapixel', 55);
        $aiSeconds = 0.0;
        $sourceInfo = [];

        foreach ($sources as $path) {
            [$w, $h] = $this->dimensions($path);
            $mp = $w * $h / 1e6;
            $aiSeconds += $mp * $secPerMp * $aiPassesPerSource;
            $sourceInfo[] = ['path' => $path, 'width' => $w, 'height' => $h];
        }

        return [
            'sources' => $sourceInfo,
            'ai_passes' => $aiPassesPerSource * count($sources),
            'variants' => $variantsPerSource * count($sources),
            'estimated_ai_minutes' => (int) ceil($aiSeconds / 60),
        ];
    }

    /**
     * Voert de volledige matrix uit en retourneert de resultaatstructuur
     * (per bron: info, haalbaarheid, baseline, varianten met metingen en scores).
     */
    public function run(
        array $sources,
        array $axes,
        string $targetSize,
        int $targetDpi,
        string $runDir,
        ?\Closure $onEvent = null,
    ): array {
        $say = fn (string $msg) => $onEvent ? $onEvent($msg) : null;

        $box = $this->dpiValidator->pixelsAtDpi($targetSize, $targetDpi);
        if (! $box) {
            throw new RuntimeException("Onbekend printformaat: {$targetSize}");
        }

        $results = [
            'run_dir' => $runDir,
            'target_size' => $targetSize,
            'target_dpi' => $targetDpi,
            'target_box' => $box,
            'axes' => $axes,
            'weights' => config('posterforge.benchmark.weights'),
            'started_at' => now()->toDateTimeString(),
            'sources' => [],
        ];

        foreach ($sources as $sourcePath) {
            $results['sources'][] = $this->runSource($sourcePath, $axes, $box, $targetDpi, $runDir, $say);
        }

        $results['finished_at'] = now()->toDateTimeString();

        return $results;
    }

    private function runSource(
        string $sourcePath,
        array $axes,
        array $box,
        int $targetDpi,
        string $runDir,
        \Closure $say,
    ): array {
        $slug = Str::slug(pathinfo($sourcePath, PATHINFO_FILENAME));
        $dir = $runDir . DIRECTORY_SEPARATOR . $slug;
        foreach (['cache', 'variants', 'sheets'] as $sub) {
            $p = $dir . DIRECTORY_SEPARATOR . $sub;
            if (! is_dir($p)) {
                mkdir($p, 0755, true);
            }
        }
        $sheetsDir = $dir . DIRECTORY_SEPARATOR . 'sheets';

        // Schijf-zuinige modus: crops direct bewaren, grote bestanden opruimen.
        $keep = (bool) (config('posterforge.benchmark.keep_files', false));

        [$w, $h] = $this->dimensions($sourcePath);
        $say("Bron {$slug} ({$w}x{$h}): tile-analyse...");

        $cfg = config('posterforge.benchmark');
        $block = (int) $cfg['blocks']['size'];

        // ── Geometrie: cover-resize + center-crop naar de doelbox (geen stretch) ──
        $W = $box['width'];
        $H = $box['height'];
        $F = max($W / $w, $H / $h);
        $ox = ($w * $F - $W) / 2;
        $oy = ($h * $F - $H) / 2;

        // ── Meetblokken uit de bron, gemapt naar doelcoördinaten ──
        $map = $this->metrics->tileMap($sourcePath, $block);
        $detailRects = $this->mapTiles(
            $this->metrics->detailTiles($map, $cfg['blocks']['detail_count'] * 2),
            $block, $F, $ox, $oy, $W, $H, $cfg['blocks']['detail_count'],
        );
        $edgeRects = $this->mapTiles(
            $this->metrics->edgeTiles($map, $cfg['blocks']['edge_count'] * 2),
            $block, $F, $ox, $oy, $W, $H, $cfg['blocks']['edge_count'],
        );

        // Crop-centra voor de contact-sheets (detailrijkste en vlakste venster).
        $cropTargetPx = (int) $cfg['contact_sheet']['crop_size'];
        $detailCenter = $this->toTarget($this->metrics->bestWindowCenter($map, $cropTargetPx / $F, richest: true), $F, $ox, $oy);
        $flatCenter = $this->toTarget($this->metrics->bestWindowCenter($map, $cropTargetPx / $F, richest: false), $F, $ox, $oy);

        // ── Haalbaarheid: effectieve DPI per printformaat na één 4x AI-pass ──
        $feasibility = $this->feasibilityTable($w, $h);

        // ── Bicubic-baseline (nulmeting, zelfde doelbox) ──
        $say("Bron {$slug}: bicubic-baseline genereren + meten...");
        $baselinePath = $dir . DIRECTORY_SEPARATOR . 'baseline.png';
        $this->processor->renderToTarget($sourcePath, $baselinePath, $box, $targetDpi, (string) $cfg['baseline_filter'], 0);
        $baseline = $this->measure($baselinePath, $detailRects, $edgeRects, $cfg);
        $baselineCrops = $this->sheetCrops($baselinePath, 'baseline', $detailCenter, $flatCenter, $cropTargetPx, $W, $H, $sheetsDir);
        if (! $keep) {
            @unlink($baselinePath);
        }

        // ── Matrix ──
        $requiredScale = $F;
        $aiScale = $this->processor->aiScaleFor($requiredScale);
        $cache = $dir . DIRECTORY_SEPARATOR . 'cache';
        $variants = [];

        foreach ($axes['pre_denoise'] as $pre) {
            if ($pre !== 'off') {
                $say("Bron {$slug}: wavelet pre-denoise ({$pre})...");
            }
            $preInput = $this->processor->preDenoise(
                $sourcePath,
                $cache . DIRECTORY_SEPARATOR . "pre_{$pre}.png",
                $pre,
            );

            foreach ($axes['model'] as $model) {
                $say("Bron {$slug}: AI-pass {$model} (pre={$pre}, {$aiScale}x)...");
                $aiPath = $cache . DIRECTORY_SEPARATOR . "ai_{$pre}_{$model}_s{$aiScale}.png";
                $aiSeconds = $this->processor->aiPass($preInput, $aiPath, $model, $aiScale);

                foreach ($axes['blend_bicubic'] as $blend) {
                    $postInput = $this->processor->blend(
                        $preInput, $aiPath, $blend, $aiScale,
                        $cache . DIRECTORY_SEPARATOR . "bicubic_{$pre}_s{$aiScale}.png",
                        $cache . DIRECTORY_SEPARATOR . "blend_{$pre}_{$model}_b{$blend}.png",
                    );

                    foreach ($axes['sharpen'] as $sharpen) {
                        $id = "{$model}__pre-{$pre}__blend{$blend}__sh{$sharpen}";
                        $variantPath = $dir . DIRECTORY_SEPARATOR . 'variants' . DIRECTORY_SEPARATOR . $id . '.png';

                        $this->processor->renderToTarget($postInput, $variantPath, $box, $targetDpi, 'Lanczos', $sharpen);

                        $say("Bron {$slug}: meten {$id}...");
                        $m = $this->measure($variantPath, $detailRects, $edgeRects, $cfg);
                        $ratios = $this->scorer->ratios($m, $baseline);
                        $crops = $this->sheetCrops($variantPath, $id, $detailCenter, $flatCenter, $cropTargetPx, $W, $H, $sheetsDir);

                        $variants[] = [
                            'id' => $id,
                            'config' => [
                                'model' => $model,
                                'pre_denoise' => $pre,
                                'blend_bicubic' => $blend,
                                'sharpen' => $sharpen,
                            ],
                            'path' => $keep ? $variantPath : null,
                            'crops' => $crops,
                            'ai_scale' => $aiScale,
                            'ai_seconds' => $aiSeconds,
                            'metrics' => $m,
                            'ratios' => $ratios,
                            'scores' => $this->scorer->score($ratios, $m),
                        ];

                        if (! $keep) {
                            @unlink($variantPath);
                        }
                    }

                    if (! $keep && $blend > 0) {
                        @unlink($postInput);
                    }
                }

                if (! $keep) {
                    @unlink($aiPath);
                }
            }

            if (! $keep) {
                @unlink($cache . DIRECTORY_SEPARATOR . "bicubic_{$pre}_s{$aiScale}.png");
                if ($preInput !== $sourcePath) {
                    @unlink($preInput);
                }
            }
        }

        usort($variants, fn ($a, $b) => $b['scores']['total'] <=> $a['scores']['total']);

        return [
            'slug' => $slug,
            'source' => $sourcePath,
            'width' => $w,
            'height' => $h,
            'dir' => $dir,
            'required_scale' => round($requiredScale, 2),
            'ai_scale' => $aiScale,
            // > 1.0 betekent: na de AI-pass was nog klassieke vergroting nodig.
            'lanczos_up_factor' => round(max(1.0, $requiredScale / $aiScale), 2),
            'feasibility' => $feasibility,
            'baseline' => ['path' => $keep ? $baselinePath : null, 'metrics' => $baseline, 'crops' => $baselineCrops],
            'detail_center' => $detailCenter,
            'flat_center' => $flatCenter,
            'variants' => $variants,
        ];
    }

    private function measure(string $path, array $detailRects, array $edgeRects, array $cfg): array
    {
        return [
            'noise_sd' => $this->metrics->noiseSd($path, 64, (int) $cfg['blocks']['noise_count']),
            'sharpness' => $this->metrics->laplacianSd($path),
            'detail' => $this->metrics->rectLaplacian($path, $detailRects),
            'texture' => $this->metrics->rectTexture($path, $detailRects),
            'edges' => $this->metrics->rectSobelEnergy($path, $edgeRects),
        ];
    }

    // ─── Hulpfuncties ───────────────────────────────────────────

    /**
     * Bron-tiles naar doel-rects mappen; tiles die (deels) buiten de
     * center-crop vallen worden overgeslagen. Neemt de top $count overlevers.
     */
    private function mapTiles(array $tiles, int $block, float $F, float $ox, float $oy, int $W, int $H, int $count): array
    {
        $size = (int) round($block * $F);
        $rects = [];

        foreach ($tiles as $t) {
            $x = (int) round($t['tx'] * $block * $F - $ox);
            $y = (int) round($t['ty'] * $block * $F - $oy);

            if ($x < 0 || $y < 0 || $x + $size > $W || $y + $size > $H) {
                continue;
            }

            $rects[] = ['x' => $x, 'y' => $y, 'w' => $size, 'h' => $size];
            if (count($rects) >= $count) {
                break;
            }
        }

        if (count($rects) < max(3, (int) ($count * 0.3))) {
            throw new RuntimeException('Te weinig meetblokken binnen de doel-crop; kies een doelformaat dichter bij de bron-verhouding.');
        }

        return $rects;
    }

    /**
     * 100%-uitsnedes voor de contact-sheets, direct na de meting bewaard
     * zodat het full-size bestand daarna weg kan (schijf-zuinige modus).
     */
    private function sheetCrops(string $path, string $name, array $detailCenter, array $flatCenter, int $size, int $W, int $H, string $sheetsDir): array
    {
        $crops = [];
        foreach (['detail' => $detailCenter, 'flat' => $flatCenter] as $type => $center) {
            $x = max(0, min($W - $size, $center['x'] - intdiv($size, 2)));
            $y = max(0, min($H - $size, $center['y'] - intdiv($size, 2)));
            $out = $sheetsDir . DIRECTORY_SEPARATOR . "{$name}_{$type}.png";
            $this->magick->run([
                $path . '[0]', '-crop', "{$size}x{$size}+{$x}+{$y}", '+repage', $out,
            ], (int) config('posterforge.benchmark.magick_timeout', 600));
            $crops[$type] = $out;
        }

        return $crops;
    }

    private function toTarget(array $sourceCenter, float $F, float $ox, float $oy): array
    {
        return [
            'x' => (int) round($sourceCenter['x'] * $F - $ox),
            'y' => (int) round($sourceCenter['y'] * $F - $oy),
        ];
    }

    /** Effectieve DPI per printformaat na één 4x AI-pass, met eerlijk verdict. */
    private function feasibilityTable(int $w, int $h): array
    {
        $qcDpi = config('posterforge.qc.dpi');
        $minDpi = (int) config('posterforge.autotune.min_dpi', 200);
        $table = [];

        foreach (config('posterforge.qc.sizes', []) as $size) {
            $dpi = $this->dpiValidator->effectiveDpiFor($w * 4, $h * 4, $size);
            if (! $dpi) {
                continue;
            }

            $table[$size] = [
                ...$dpi,
                'status' => $dpi['min_dpi'] >= $qcDpi['ideal']
                    ? 'ideal'
                    : ($dpi['min_dpi'] >= $qcDpi['acceptable'] ? 'acceptable' : 'insufficient'),
                'sellable' => $dpi['min_dpi'] >= $minDpi,
            ];
        }

        return $table;
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
