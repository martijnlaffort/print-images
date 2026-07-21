<?php

namespace App\Services\Benchmark;

/**
 * Gewogen score voor een gemeten variant t.o.v. de bicubic-baseline.
 * Gebruikt door de benchmark én de per-afbeelding autotune, zodat beide
 * exact dezelfde definitie van "beter" hanteren.
 */
class BenchmarkScorer
{
    public function ratios(array $m, array $baseline): array
    {
        $ratio = fn (string $k) => $baseline[$k] > 0 ? round($m[$k] / $baseline[$k], 3) : 0.0;

        return [
            'detail' => $ratio('detail'),
            'texture' => $ratio('texture'),
            'edges' => $ratio('edges'),
            'sharpness' => $ratio('sharpness'),
        ];
    }

    /**
     * Ratio's worden afgetopt (ratio_caps) zodat oversharpen-artefacten
     * niet oneindig belonen; ruis scoort 1.0 binnen de doelband en zakt
     * daarbuiten (te schoon = "plastic", te ruizig = korrelklacht).
     */
    public function score(array $ratios, array $m, ?array $weights = null): array
    {
        $cfg = config('posterforge.benchmark');
        $caps = $cfg['ratio_caps'];
        $weights ??= $cfg['weights'];
        $capped = fn (float $ratio, float $cap) => round(min($ratio, $cap) / $cap, 3);

        $noise = $m['noise_sd'];
        $band = $cfg['noise_target'];
        if ($noise < $band['min']) {
            $noiseScore = $band['min'] > 0 ? round($noise / $band['min'], 3) : 1.0;
        } elseif ($noise > $band['max']) {
            $noiseScore = round($band['max'] / $noise, 3);
        } else {
            $noiseScore = 1.0;
        }

        $scores = [
            'detail' => $capped($ratios['detail'], (float) $caps['detail']),
            'edges' => $capped($ratios['edges'], (float) $caps['edges']),
            'texture' => $capped($ratios['texture'], (float) $caps['texture']),
            'noise' => $noiseScore,
        ];

        $totalWeight = array_sum($weights);
        $total = 0.0;
        foreach ($scores as $key => $value) {
            $total += ($weights[$key] ?? 0) * $value;
        }
        $scores['total'] = $totalWeight > 0 ? round($total / $totalWeight, 4) : 0.0;

        return $scores;
    }
}
