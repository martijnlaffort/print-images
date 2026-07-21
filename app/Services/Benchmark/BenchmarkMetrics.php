<?php

namespace App\Services\Benchmark;

use App\Services\MagickService;
use RuntimeException;

/**
 * Objectieve metingen voor het benchmark-harnas. Alle blok-gebaseerde
 * metingen gebeuren op posities die uit de BRON gekozen zijn en naar
 * doelcoördinaten gemapt worden, zodat elke variant (en de bicubic-
 * baseline) op exact dezelfde beeldinhoud wordt gemeten.
 */
class BenchmarkMetrics
{
    public function __construct(
        private MagickService $magick,
    ) {}

    private function timeout(): int
    {
        return (int) config('posterforge.benchmark.magick_timeout', 600);
    }

    /**
     * Tile-map van een afbeelding: per tile de MAD (vlakheid) en de
     * absolute Sobel-gradiëntenergie (randen), beide 0-255-schaal.
     * Retourneert ['tiles' => [['tx','ty','mad','edge'], ...], 'tw', 'th', 'block'].
     */
    public function tileMap(string $path, int $block, bool $withEdges = true): array
    {
        [$width, $height] = $this->dimensions($path);

        $tw = intdiv($width, $block);
        $th = intdiv($height, $block);
        if ($tw < 2 || $th < 2) {
            throw new RuntimeException("Afbeelding te klein voor tile-analyse: {$path}");
        }
        $cw = $tw * $block;
        $ch = $th * $block;

        $madTxt = $this->magick->run([
            $path . '[0]', '-colorspace', 'Gray',
            '-crop', "{$cw}x{$ch}+0+0", '+repage',
            '-write', 'mpr:I',
            '-scale', "{$tw}x{$th}!",
            '-sample', "{$cw}x{$ch}!",
            'mpr:I',
            '-compose', 'difference', '-composite',
            '-scale', "{$tw}x{$th}!",
            '-depth', '16', 'txt:-',
        ], $this->timeout());

        // Abs-gradiënt per richting: Sobel met bias 50% centreert rond
        // mid-grijs; solarize+negate+level maakt er |gradiënt| van.
        $edgeTxt = ! $withEdges ? '' : $this->magick->run([
            $path . '[0]', '-colorspace', 'Gray',
            '-crop', "{$cw}x{$ch}+0+0", '+repage',
            '-define', 'convolve:bias=50%',
            '(', '-clone', '0', '-morphology', 'Convolve', 'Sobel:0',
            '-solarize', '50%', '-negate', '-level', '50%,100%', ')',
            '(', '-clone', '0', '-morphology', 'Convolve', 'Sobel:90',
            '-solarize', '50%', '-negate', '-level', '50%,100%', ')',
            '-delete', '0',
            '-compose', 'Plus', '-composite',
            '-scale', "{$tw}x{$th}!",
            '-depth', '16', 'txt:-',
        ], $this->timeout());

        $mad = $this->parseTxtMap($madTxt);
        $edge = $this->parseTxtMap($edgeTxt);

        $tiles = [];
        foreach ($mad as $key => $value) {
            [$tx, $ty] = explode(',', $key);
            $tiles[] = [
                'tx' => (int) $tx,
                'ty' => (int) $ty,
                'mad' => $value,
                'edge' => $edge[$key] ?? 0.0,
            ];
        }

        if (count($tiles) < 4) {
            throw new RuntimeException("Tile-analyse leverde te weinig tiles op: {$path}");
        }

        return ['tiles' => $tiles, 'tw' => $tw, 'th' => $th, 'block' => $block];
    }

    /** Detailrijkste tiles (hoogste MAD): hier moet detail behouden blijven. */
    public function detailTiles(array $map, int $count): array
    {
        $tiles = $map['tiles'];
        usort($tiles, fn ($a, $b) => $b['mad'] <=> $a['mad']);

        return array_slice($tiles, 0, $count);
    }

    /** Randrijkste tiles (hoogste Sobel-energie): meetpunten voor randscherpte. */
    public function edgeTiles(array $map, int $count): array
    {
        $tiles = $map['tiles'];
        usort($tiles, fn ($a, $b) => $b['edge'] <=> $a['edge']);

        return array_slice($tiles, 0, $count);
    }

    /**
     * Bron-coördinaat (px, centrum) van het venster met de hoogste of
     * laagste totale detaildichtheid: representatiever voor "detailrijk
     * gebied" dan de enkele tile met de sterkste rand.
     */
    public function bestWindowCenter(array $map, float $windowSrcPx, bool $richest): array
    {
        $block = $map['block'];
        $radius = max(0, (int) floor($windowSrcPx / $block / 2));

        $grid = [];
        foreach ($map['tiles'] as $t) {
            $grid[$t['ty']][$t['tx']] = $t['mad'];
        }

        $best = null;
        $bestSum = null;
        foreach ($map['tiles'] as $t) {
            $sum = 0.0;
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                for ($dx = -$radius; $dx <= $radius; $dx++) {
                    $sum += $grid[$t['ty'] + $dy][$t['tx'] + $dx] ?? 0.0;
                }
            }
            if ($bestSum === null || ($richest ? $sum > $bestSum : $sum < $bestSum)) {
                $bestSum = $sum;
                $best = $t;
            }
        }

        return [
            'x' => (int) round(($best['tx'] + 0.5) * $block),
            'y' => (int) round(($best['ty'] + 0.5) * $block),
        ];
    }

    /**
     * Ruis-sd: exacte std-dev (0-255) op de N vlakste blokken van de
     * afbeelding zelf — zelfde methode als de QC-meting, doelband 1.0-3.0.
     */
    public function noiseSd(string $path, int $block = 64, int $count = 50): float
    {
        $map = $this->tileMap($path, $block, withEdges: false);
        $tiles = $map['tiles'];
        usort($tiles, fn ($a, $b) => $a['mad'] <=> $b['mad']);
        $flattest = array_slice($tiles, 0, min($count, count($tiles)));

        $rects = array_map(fn ($t) => [
            'x' => $t['tx'] * $block,
            'y' => $t['ty'] * $block,
            'w' => $block,
            'h' => $block,
        ], $flattest);

        return $this->rectStdDev($path, $rects, []);
    }

    /** Globale scherpte: std-dev van de Laplacian (0-255-schaal). */
    public function laplacianSd(string $path): float
    {
        $out = trim($this->magick->run([
            $path . '[0]', '-colorspace', 'Gray',
            '-define', 'convolve:bias=50%',
            '-morphology', 'Convolve', 'Laplacian:0',
            '-format', '%[fx:standard_deviation*255]', 'info:',
        ], $this->timeout()));

        return round((float) $out, 3);
    }

    /** Gemiddelde Laplacian-sd over rects: fijn detail op de meetblokken. */
    public function rectLaplacian(string $path, array $rects): float
    {
        return $this->rectStdDev($path, $rects, [
            '-define', 'convolve:bias=50%',
            '-morphology', 'Convolve', 'Laplacian:0',
        ]);
    }

    /** Gemiddelde plain sd over rects: lokale textuur ("plastic"-detectie). */
    public function rectTexture(string $path, array $rects): float
    {
        return $this->rectStdDev($path, $rects, []);
    }

    /**
     * Randscherpte over rects: gradiëntenergie sqrt(sdX² + sdY²) van de
     * Sobel-respons, gemiddeld over de blokken.
     */
    public function rectSobelEnergy(string $path, array $rects): float
    {
        $sdX = $this->rectStdDevAll($path, $rects, [
            '-define', 'convolve:bias=50%',
            '-morphology', 'Convolve', 'Sobel:0',
        ]);
        $sdY = $this->rectStdDevAll($path, $rects, [
            '-define', 'convolve:bias=50%',
            '-morphology', 'Convolve', 'Sobel:90',
        ]);

        $n = min(count($sdX), count($sdY));
        if ($n === 0) {
            throw new RuntimeException("Sobel-meting leverde geen waarden op: {$path}");
        }

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += sqrt($sdX[$i] ** 2 + $sdY[$i] ** 2);
        }

        return round($sum / $n, 3);
    }

    /** Mean van rectStdDevAll. */
    private function rectStdDev(string $path, array $rects, array $filter): float
    {
        $values = $this->rectStdDevAll($path, $rects, $filter);

        return round(array_sum($values) / count($values), 3);
    }

    /**
     * Exacte std-dev (0-255) per rect, in één magick-aanroep voor alle
     * rects. $filter (bv. een convolutie) geldt voor elk blok-frame.
     */
    private function rectStdDevAll(string $path, array $rects, array $filter): array
    {
        if (! $rects) {
            throw new RuntimeException('Geen meetblokken opgegeven.');
        }

        $args = [$path . '[0]', '-colorspace', 'Gray'];
        foreach ($rects as $r) {
            array_push($args, '(', '-clone', '0', '-crop',
                "{$r['w']}x{$r['h']}+{$r['x']}+{$r['y']}", '+repage', ')');
        }
        $args = array_merge($args, ['-delete', '0'], $filter,
            ['-format', "%[standard-deviation]\n", 'info:']);

        $out = $this->magick->run($args, $this->timeout());

        $values = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (is_numeric(trim($line))) {
                $values[] = (float) trim($line) / 65535 * 255;
            }
        }
        if (! $values) {
            throw new RuntimeException("Blokmeting leverde geen waarden op: {$path}");
        }

        return $values;
    }

    /** Parse "x,y: (value..." txt-output naar ["x,y" => 0-255 waarde]. */
    private function parseTxtMap(string $txt): array
    {
        $map = [];
        foreach (explode("\n", $txt) as $line) {
            if (preg_match('/^(\d+),(\d+):\s*\((\d+)/', trim($line), $m)) {
                $map["{$m[1]},{$m[2]}"] = round((int) $m[3] / 65535 * 255, 3);
            }
        }

        return $map;
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
