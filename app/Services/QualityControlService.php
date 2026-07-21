<?php

namespace App\Services;

use App\Models\Poster;
use App\Models\QcReport;
use RuntimeException;

class QualityControlService
{
    public function __construct(
        private MagickService $magick,
        private DpiValidator $dpiValidator,
    ) {}

    /**
     * Full print-readiness analysis of a single image.
     * Read-only: never modifies the input file.
     */
    public function analyze(string $path): array
    {
        if (! file_exists($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $info = $this->identify($path);
        $blocks = $this->flattestBlocks($path, $info['width'], $info['height']);
        $noise = $this->blockNoise($path, $blocks['flattest']);
        $grain = $this->blockGrain($path, $blocks['flattest']);
        $sharpness = $this->sharpness($path);
        $color = $this->channelMeans($path);

        $cfg = config('posterforge.qc');

        $warnings = [];
        if (strtoupper($info['format']) === 'JPEG') {
            $warnings[] = 'Bron is JPEG: compressie-artefacten kunnen als korrel printen. Gebruik PNG of TIFF.';
        }

        return [
            'file' => $path,
            'filename' => basename($path),
            'filesize' => filesize($path),
            'width' => $info['width'],
            'height' => $info['height'],
            'format' => $info['format'],
            'mode' => $info['mode'],
            'density' => $info['density'],
            'icc' => $info['icc'],
            'noise' => [
                'flattest_mean_sd' => $noise['mean'],
                'block_size' => $cfg['block_size'],
                'blocks_used' => count($blocks['flattest']),
                'flat10_approx_sd' => $blocks['flat10_approx_sd'],
                'status' => $this->level($noise['mean'], $cfg['noise']),
            ],
            'grain' => [
                'flattest_mean_laplacian' => $grain['mean'],
                'status' => $this->level($grain['mean'], $cfg['grain']),
            ],
            'sharpness' => $sharpness,
            'color' => $color,
            'dpi_table' => $this->dpiTable($info['width'], $info['height']),
            'flattest_block' => $blocks['flattest'][0] ?? null,
            'warnings' => $warnings,
        ];
    }

    /**
     * Verdict for a metrics array. $requirePrintReady: true for pipeline
     * output/exports — dan zijn RGB zonder alfa, ingebed ICC-profiel en
     * PNG harde eisen (FAIL). Voor bronbestanden (false) blijft een
     * ontbrekend profiel een waarschuwing: de pipeline bedt er zelf een in.
     */
    public function verdict(array $metrics, bool $requirePrintReady = false): array
    {
        $fail = [];
        $warn = [];
        $cfg = config('posterforge.qc');

        if ($metrics['noise']['status'] === 'noisy') {
            $fail[] = sprintf('Ruis in vlakste gebieden te hoog: sd %.2f (> %.1f)', $metrics['noise']['flattest_mean_sd'], $cfg['noise']['acceptable']);
        } elseif ($metrics['noise']['status'] === 'acceptable') {
            $warn[] = sprintf('Lichte ruis in vlakste gebieden: sd %.2f', $metrics['noise']['flattest_mean_sd']);
        }

        // Grain (Laplacian) can't distinguish noise from fine patterns/line art,
        // so a high value is only ever a warning — never an export blocker.
        if ($metrics['grain']['status'] === 'noisy') {
            $warn[] = sprintf(
                'Veel fijn detail/korrel: %.2f (> %.1f) — kan ook patroon of lijnwerk zijn; controleer de crop visueel.',
                $metrics['grain']['flattest_mean_laplacian'],
                $cfg['grain']['acceptable'],
            );
        } elseif ($metrics['grain']['status'] === 'acceptable') {
            $warn[] = sprintf('Lichte fijne korrel: %.2f', $metrics['grain']['flattest_mean_laplacian']);
        }

        if (! $metrics['icc']['embedded']) {
            $msg = 'Geen ICC-profiel ingebed: printer gokt de kleurconversie (doffere/koelere kleuren).';
            $requirePrintReady ? $fail[] = $msg : $warn[] = $msg;
        }

        if ($requirePrintReady) {
            $mode = $metrics['mode'] ?? null;
            if ($mode && ! $mode['print_ready']) {
                $fail[] = "Kleurmodus {$mode['label']} — printbestand moet RGB zonder alfakanaal zijn.";
            }
            if (strtoupper($metrics['format']) !== 'PNG') {
                $fail[] = "Bestandsformaat {$metrics['format']} — printbestand moet PNG zijn.";
            }
        }

        foreach ($metrics['warnings'] as $w) {
            $warn[] = $w;
        }

        $insufficient = array_keys(array_filter(
            $metrics['dpi_table'],
            fn ($row) => $row['status'] === 'insufficient'
        ));
        if ($insufficient) {
            $warn[] = 'Onvoldoende resolutie voor: ' . implode(', ', $insufficient) . ' cm';
        }

        if ($fail) {
            return ['verdict' => 'fail', 'reasons' => array_merge($fail, $warn)];
        }
        if ($warn) {
            return ['verdict' => 'warn', 'reasons' => $warn];
        }

        return ['verdict' => 'pass', 'reasons' => []];
    }

    /**
     * Before/after comparison for the denoise step: noise delta, detail
     * loss (variance-of-Laplacian based), over-denoise warning.
     */
    public function compare(array $before, array $after): array
    {
        $lossPercent = $before['sharpness'] > 0
            ? round((1 - $after['sharpness'] / $before['sharpness']) * 100, 1)
            : 0.0;

        $maxLoss = (float) config('posterforge.qc.detail_loss_warn_percent', 30);

        return [
            'noise_before' => $before['noise']['flattest_mean_sd'],
            'noise_after' => $after['noise']['flattest_mean_sd'],
            'noise_delta' => round($after['noise']['flattest_mean_sd'] - $before['noise']['flattest_mean_sd'], 2),
            'grain_before' => $before['grain']['flattest_mean_laplacian'],
            'grain_after' => $after['grain']['flattest_mean_laplacian'],
            'grain_delta' => round($after['grain']['flattest_mean_laplacian'] - $before['grain']['flattest_mean_laplacian'], 2),
            'sharpness_before' => $before['sharpness'],
            'sharpness_after' => $after['sharpness'],
            'detail_loss_percent' => $lossPercent,
            'too_aggressive' => $lossPercent > $maxLoss,
        ];
    }

    /**
     * Side-by-side 100%-zoom crops of the flattest area, before | after.
     * Left half = before, right half = after (UI labels the halves).
     */
    public function comparisonCrop(string $beforePath, string $afterPath, array $flattestBlock, string $outputPath): string
    {
        $size = (int) config('posterforge.qc.crop_size', 400);
        $blockSize = (int) config('posterforge.qc.block_size', 64);

        [$w, $h] = $this->dimensions($beforePath);
        $cx = $flattestBlock['x'] + intdiv($blockSize, 2);
        $cy = $flattestBlock['y'] + intdiv($blockSize, 2);
        $x = max(0, min($w - $size, $cx - intdiv($size, 2)));
        $y = max(0, min($h - $size, $cy - intdiv($size, 2)));

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpBefore = $outputPath . '.before.tmp.png';
        $tmpAfter = $outputPath . '.after.tmp.png';

        try {
            $timeout = (int) config('posterforge.qc.magick_timeout', 300);
            $this->magick->run([$beforePath, '-crop', "{$size}x{$size}+{$x}+{$y}", '+repage', $tmpBefore], $timeout);
            $this->magick->run([$afterPath, '-crop', "{$size}x{$size}+{$x}+{$y}", '+repage', $tmpAfter], $timeout);
            $this->magick->run([
                $tmpBefore,
                '-gravity', 'east', '-background', '#333333', '-splice', '6x0',
                $tmpAfter, '+append', $outputPath,
            ], $timeout);
        } finally {
            @unlink($tmpBefore);
            @unlink($tmpAfter);
        }

        return $outputPath;
    }

    /**
     * Analyze a file and persist the result as a QcReport.
     */
    public function runAndStore(
        string $path,
        string $phase,
        ?int $posterId = null,
        bool $requirePrintReady = false,
        ?array $comparison = null,
        ?string $comparisonImagePath = null,
        ?string $batchId = null,
        ?array $metrics = null,
    ): QcReport {
        $metrics ??= $this->analyze($path);
        $verdict = $this->verdict($metrics, $requirePrintReady);

        if ($comparison && $comparison['too_aggressive']) {
            $verdict['reasons'][] = sprintf(
                'Denoise te agressief: %.1f%% detailverlies (> %d%%). Gebruik een lichtere instelling.',
                $comparison['detail_loss_percent'],
                (int) config('posterforge.qc.detail_loss_warn_percent', 30),
            );
            if ($verdict['verdict'] === 'pass') {
                $verdict['verdict'] = 'warn';
            }
        }

        return QcReport::create([
            'poster_id' => $posterId,
            'source_path' => $path,
            'phase' => $phase,
            'verdict' => $verdict['verdict'],
            'metrics' => $metrics,
            'reasons' => $verdict['reasons'],
            'comparison' => $comparison,
            'comparison_image_path' => $comparisonImagePath,
            'batch_id' => $batchId,
        ]);
    }

    /**
     * Export gate: a poster with a failing QC verdict must not slip
     * through silently. Runs a fresh source QC when none exists yet.
     */
    public function gateForExport(Poster $poster): QcReport
    {
        $report = $poster->qcReports()->orderByDesc('created_at')->first();

        if (! $report) {
            $path = $poster->upscaled_path ?? $poster->original_path;
            $report = $this->runAndStore($path, 'source', $poster->id);
        }

        return $report;
    }

    // ─── Metingen ───────────────────────────────────────────────

    private function identify(string $path): array
    {
        $out = trim($this->magick->run([
            'identify', '-quiet', '-format',
            '%w|%h|%m|%A|%[colorspace]|%x|%y|%U|%[icc:description]',
            $path . '[0]',
        ], 60));

        $parts = explode('|', $out);
        if (count($parts) < 8) {
            throw new RuntimeException("Cannot identify image: {$path}");
        }

        [$w, $h, $format, $alpha, $colorspace, $dx, $dy, $units] = $parts;
        $iccDesc = $parts[8] ?? '';

        // Kleurmodus: printbestanden moeten RGB zonder alfakanaal zijn.
        $hasAlpha = ! in_array(strtolower(trim($alpha)), ['undefined', 'false', 'off', ''], true);
        $base = in_array(strtolower($colorspace), ['srgb', 'rgb'], true) ? 'RGB' : $colorspace;
        $mode = [
            'colorspace' => $colorspace,
            'has_alpha' => $hasAlpha,
            'label' => $base . ($hasAlpha ? 'A' : ''),
            'print_ready' => $base === 'RGB' && ! $hasAlpha,
        ];

        // Normalize density to DPI regardless of stored units.
        $toDpi = fn (float $v) => match ($units) {
            'PixelsPerCentimeter' => $v * 2.54,
            'PixelsPerInch' => $v,
            default => 0.0,
        };

        return [
            'width' => (int) $w,
            'height' => (int) $h,
            'format' => $format,
            'mode' => $mode,
            'density' => [
                'dpi_x' => round($toDpi((float) $dx)),
                'dpi_y' => round($toDpi((float) $dy)),
                'units' => $units,
            ],
            'icc' => [
                'embedded' => $iccDesc !== '',
                'description' => $iccDesc ?: null,
            ],
        ];
    }

    /**
     * Rank all blocks by flatness with a fast MAD map (mean absolute
     * deviation from the block mean, computed via box-scaling — a single
     * ~2s ImageMagick pass even on 50MP files). Exact per-block std-devs
     * are then measured only on the flattest N blocks.
     */
    private function flattestBlocks(string $path, int $width, int $height): array
    {
        $block = (int) config('posterforge.qc.block_size', 64);
        $count = (int) config('posterforge.qc.flattest_count', 50);

        $tw = intdiv($width, $block);
        $th = intdiv($height, $block);
        if ($tw < 2 || $th < 2) {
            throw new RuntimeException('Image too small for QC block analysis.');
        }
        $cw = $tw * $block;
        $ch = $th * $block;

        $txt = $this->magick->run([
            $path . '[0]', '-colorspace', 'Gray',
            '-crop', "{$cw}x{$ch}+0+0", '+repage',
            '-write', 'mpr:I',
            '-scale', "{$tw}x{$th}!",
            '-sample', "{$cw}x{$ch}!",
            'mpr:I',
            '-compose', 'difference', '-composite',
            '-scale', "{$tw}x{$th}!",
            '-depth', '16', 'txt:-',
        ], (int) config('posterforge.qc.magick_timeout', 300));

        $tiles = [];
        foreach (explode("\n", $txt) as $line) {
            if (preg_match('/^(\d+),(\d+):\s*\((\d+)/', trim($line), $m)) {
                $tiles[] = ['tx' => (int) $m[1], 'ty' => (int) $m[2], 'mad' => (int) $m[3]];
            }
        }
        if (count($tiles) < $count) {
            throw new RuntimeException('QC block analysis produced too few tiles.');
        }

        usort($tiles, fn ($a, $b) => $a['mad'] <=> $b['mad']);

        $flattest = array_map(
            fn ($t) => ['x' => $t['tx'] * $block, 'y' => $t['ty'] * $block],
            array_slice($tiles, 0, $count),
        );

        // Indicative broader-selection noise estimate (MAD → sd for Gaussian).
        $tenPct = array_slice($tiles, 0, max(1, (int) (count($tiles) * 0.10)));
        $madMean = array_sum(array_column($tenPct, 'mad')) / count($tenPct);
        $flat10 = round($madMean / 65535 * 255 * 1.2533, 2);

        return ['flattest' => $flattest, 'flat10_approx_sd' => $flat10];
    }

    private function blockNoise(string $path, array $blocks): array
    {
        $values = $this->perBlockProperty($path, $blocks, []);

        return ['mean' => round(array_sum($values) / count($values), 2)];
    }

    private function blockGrain(string $path, array $blocks): array
    {
        $values = $this->perBlockProperty($path, $blocks, ['-morphology', 'Convolve', 'Laplacian:0']);

        return ['mean' => round(array_sum($values) / count($values), 2)];
    }

    /**
     * Exact std-dev (0-255 scale) per block, one magick call for all blocks.
     * $filter args (e.g. a Laplacian convolution) apply to every block frame.
     */
    private function perBlockProperty(string $path, array $blocks, array $filter): array
    {
        $block = (int) config('posterforge.qc.block_size', 64);

        $args = [$path . '[0]', '-colorspace', 'Gray'];
        foreach ($blocks as $b) {
            array_push($args, '(', '-clone', '0', '-crop', "{$block}x{$block}+{$b['x']}+{$b['y']}", '+repage', ')');
        }
        $args = array_merge($args, ['-delete', '0'], $filter, ['-format', "%[standard-deviation]\n", 'info:']);

        $out = $this->magick->run($args, (int) config('posterforge.qc.magick_timeout', 300));

        $values = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (is_numeric(trim($line))) {
                $values[] = (float) trim($line) / 65535 * 255;
            }
        }
        if (! $values) {
            throw new RuntimeException('Per-block measurement returned no values.');
        }

        return $values;
    }

    /**
     * Full-image sharpness: std-dev of the Laplacian (0-255 scale).
     * (Variance of Laplacian is this value squared; the ratio before/after
     * is what the detail-loss check uses, so the scale choice is neutral.)
     */
    private function sharpness(string $path): float
    {
        $out = trim($this->magick->run([
            $path . '[0]', '-colorspace', 'Gray',
            '-morphology', 'Convolve', 'Laplacian:0',
            '-format', '%[fx:standard_deviation*255]', 'info:',
        ], (int) config('posterforge.qc.magick_timeout', 300)));

        return round((float) $out, 3);
    }

    private function channelMeans(string $path): array
    {
        $txt = $this->magick->run([
            $path . '[0]', '-scale', '1x1!', '-depth', '16', 'txt:-',
        ], 120);

        if (! preg_match('/\((\d+),(\d+),(\d+)/', $txt, $m)) {
            // Grayscale images report a single channel.
            if (preg_match('/\((\d+)\)/', $txt, $g)) {
                $v = round((int) $g[1] / 65535 * 255, 1);
                $m = [null, $g[1], $g[1], $g[1]];
            } else {
                throw new RuntimeException('Cannot read channel means.');
            }
        }

        $r = round((int) $m[1] / 65535 * 255, 1);
        $g = round((int) $m[2] / 65535 * 255, 1);
        $b = round((int) $m[3] / 65535 * 255, 1);
        $rMinusB = round($r - $b, 1);

        return [
            'r' => $r,
            'g' => $g,
            'b' => $b,
            'r_minus_b' => $rMinusB,
            'indication' => $rMinusB > 5 ? 'warm' : ($rMinusB < -5 ? 'koel' : 'neutraal'),
        ];
    }

    private function dpiTable(int $width, int $height): array
    {
        $cfg = config('posterforge.qc.dpi');
        $table = [];

        foreach (config('posterforge.qc.sizes', []) as $size) {
            $dpi = $this->dpiValidator->effectiveDpiFor($width, $height, $size);
            if (! $dpi) {
                continue;
            }

            $table[$size] = [
                ...$dpi,
                'status' => $dpi['min_dpi'] >= $cfg['ideal']
                    ? 'ideal'
                    : ($dpi['min_dpi'] >= $cfg['acceptable'] ? 'acceptable' : 'insufficient'),
            ];
        }

        return $table;
    }

    private function level(float $value, array $thresholds): string
    {
        if ($value < $thresholds['clean']) {
            return 'clean';
        }

        return $value <= $thresholds['acceptable'] ? 'acceptable' : 'noisy';
    }

    private function dimensions(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException("Cannot read image dimensions: {$path}");
        }

        return [(int) $info[0], (int) $info[1]];
    }
}
