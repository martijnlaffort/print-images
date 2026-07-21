<?php

namespace App\Services\Benchmark;

use App\Services\MagickService;

/**
 * Rapportage van een benchmark-run: contact-sheets (zelfde 100%-uitsnede
 * per configuratie, met labels en scores), results.json en een
 * zelfstandig report.html met scoretabellen en aanbeveling.
 */
class BenchmarkReport
{
    public function __construct(
        private MagickService $magick,
    ) {}

    public function generate(array $results): array
    {
        $cfg = config('posterforge.benchmark');
        $sheets = [];

        foreach ($results['sources'] as $i => $source) {
            $sheets[$source['slug']]['detail'] = $this->contactSheet($results, $source, 'detail', $cfg);
            if (! empty($cfg['contact_sheet']['flat_sheet'])) {
                $sheets[$source['slug']]['flat'] = $this->contactSheet($results, $source, 'flat', $cfg);
            }
            $results['sources'][$i]['sheets'] = $sheets[$source['slug']];
        }

        $aggregate = $this->aggregate($results);
        $results['aggregate'] = $aggregate;

        $jsonPath = $results['run_dir'] . DIRECTORY_SEPARATOR . 'results.json';
        file_put_contents($jsonPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $htmlPath = $results['run_dir'] . DIRECTORY_SEPARATOR . 'report.html';
        file_put_contents($htmlPath, $this->html($results, $aggregate));

        return ['json' => $jsonPath, 'html' => $htmlPath, 'aggregate' => $aggregate];
    }

    /**
     * Zelfde uitsnede (100%, geen zoom) uit baseline + elke variant,
     * gelabeld met configuratie en scores, als montage naast elkaar.
     * Gebruikt de crops die de runner tijdens de run bewaard heeft.
     */
    private function contactSheet(array $results, array $source, string $type, array $cfg): string
    {
        $cols = (int) $cfg['contact_sheet']['columns'];

        $entries = [[
            'label' => "BICUBIC baseline (nulmeting)\nruis " . $source['baseline']['metrics']['noise_sd'],
            'path' => $source['baseline']['crops'][$type],
        ]];

        foreach ($source['variants'] as $variant) {
            $c = $variant['config'];
            $model = str_replace(['realesrgan-', 'realesr-'], '', $c['model']);
            $entries[] = [
                'label' => sprintf(
                    "%s  pre:%s  blend:%d  sharp:%d\nscore %.2f | detail %.2fx | rand %.2fx | ruis %.1f",
                    $model, $c['pre_denoise'], $c['blend_bicubic'], $c['sharpen'],
                    $variant['scores']['total'], $variant['ratios']['detail'],
                    $variant['ratios']['edges'], $variant['metrics']['noise_sd'],
                ),
                'path' => $variant['crops'][$type],
            ];
        }

        $montageArgs = ['montage'];
        foreach ($entries as $entry) {
            array_push($montageArgs, '-label', $entry['label'], $entry['path']);
        }

        $out = $source['dir'] . DIRECTORY_SEPARATOR . "sheet_{$type}.png";
        array_push($montageArgs,
            '-tile', "{$cols}x", '-geometry', '+8+8',
            '-background', '#141414', '-fill', '#e5e5e5', '-pointsize', '13',
            $out,
        );
        $this->magick->run($montageArgs, (int) $cfg['magick_timeout']);

        return $out;
    }

    /**
     * Per bron de winnaar; overall ranking = gemiddelde score per
     * configuratie over alle bronnen.
     */
    private function aggregate(array $results): array
    {
        $byConfig = [];
        $perSource = [];

        foreach ($results['sources'] as $source) {
            $winner = $source['variants'][0] ?? null;

            $target = $results['target_size'];
            $feas = $source['feasibility'][$target] ?? null;
            $sellableSizes = array_keys(array_filter($source['feasibility'], fn ($row) => $row['sellable']));

            $perSource[$source['slug']] = [
                'winner' => $winner ? $winner['id'] : null,
                'winner_config' => $winner ? $winner['config'] : null,
                'winner_score' => $winner ? $winner['scores']['total'] : null,
                'target_feasible' => $feas ? $feas['sellable'] : false,
                'target_min_dpi' => $feas ? $feas['min_dpi'] : null,
                'max_sellable_size' => $sellableSizes ? end($sellableSizes) : null,
                'lanczos_up_factor' => $source['lanczos_up_factor'],
            ];

            foreach ($source['variants'] as $variant) {
                $byConfig[$variant['id']]['config'] = $variant['config'];
                $byConfig[$variant['id']]['scores'][] = $variant['scores']['total'];
            }
        }

        $ranking = [];
        foreach ($byConfig as $id => $data) {
            $ranking[] = [
                'id' => $id,
                'config' => $data['config'],
                'mean_score' => round(array_sum($data['scores']) / count($data['scores']), 4),
                'sources' => count($data['scores']),
            ];
        }
        usort($ranking, fn ($a, $b) => $b['mean_score'] <=> $a['mean_score']);

        return [
            'per_source' => $perSource,
            'ranking' => $ranking,
            'recommendation' => $ranking[0] ?? null,
        ];
    }

    private function html(array $results, array $aggregate): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $statusLabel = ['ideal' => 'ideaal', 'acceptable' => 'acceptabel', 'insufficient' => 'onvoldoende'];

        $rows = '';
        foreach (array_slice($aggregate['ranking'], 0, 10) as $rank => $r) {
            $c = $r['config'];
            $rows .= '<tr><td>' . ($rank + 1) . '</td><td>' . $e($c['model'])
                . '</td><td>' . $e($c['pre_denoise']) . '</td><td>' . $c['blend_bicubic']
                . '</td><td>' . $c['sharpen'] . '</td><td><b>' . number_format($r['mean_score'], 3) . '</b></td></tr>';
        }

        $rec = $aggregate['recommendation'];
        $recHtml = $rec
            ? '<p class="rec">Aanbevolen configuratie (gemiddeld over de testset): <b>' . $e($rec['id'])
                . '</b> — gemiddelde score ' . number_format($rec['mean_score'], 3) . '</p>'
            : '';

        $sourcesHtml = '';
        foreach ($results['sources'] as $source) {
            $ps = $aggregate['per_source'][$source['slug']];

            $feasRows = '';
            foreach ($source['feasibility'] as $sizeName => $row) {
                $cls = $row['sellable'] ? ($row['status'] === 'ideal' ? 'ok' : 'warn') : 'bad';
                $feasRows .= '<tr class="' . $cls . '"><td>' . $e($sizeName) . ' cm</td><td>'
                    . $row['min_dpi'] . ' DPI</td><td>' . $e($statusLabel[$row['status']] ?? $row['status'])
                    . '</td><td>' . ($row['sellable'] ? 'ja' : '<b>niet aanbieden</b>') . '</td></tr>';
            }

            $gate = '';
            if (! $ps['target_feasible']) {
                $gate = '<p class="gate">⚠ Dit ontwerp is <b>niet geschikt voor ' . $e($results['target_size'])
                    . ' cm</b> (effectief ' . $ps['target_min_dpi'] . ' DPI na AI-upscale). Grootste verdedigbare formaat: <b>'
                    . $e($ps['max_sellable_size'] ?? 'geen') . ' cm</b>. Geen enkele instelling lost dit op — te weinig bronpixels.</p>';
            } elseif ($source['lanczos_up_factor'] > 1.05) {
                $gate = '<p class="note">Na de AI-pass was nog ' . $source['lanczos_up_factor']
                    . 'x klassieke vergroting nodig voor het doelformaat — enige verzachting is daardoor inherent.</p>';
            }

            $varRows = '';
            foreach ($source['variants'] as $rank => $variant) {
                $c = $variant['config'];
                $s = $variant['scores'];
                $m = $variant['metrics'];
                $varRows .= '<tr' . ($rank === 0 ? ' class="winner"' : '') . '><td>' . ($rank + 1)
                    . '</td><td>' . $e($c['model']) . '</td><td>' . $e($c['pre_denoise'])
                    . '</td><td>' . $c['blend_bicubic'] . '</td><td>' . $c['sharpen']
                    . '</td><td><b>' . number_format($s['total'], 3) . '</b></td><td>'
                    . number_format($variant['ratios']['detail'], 2) . 'x</td><td>'
                    . number_format($variant['ratios']['edges'], 2) . 'x</td><td>'
                    . number_format($variant['ratios']['texture'], 2) . 'x</td><td>'
                    . number_format($m['noise_sd'], 2) . '</td></tr>';
            }

            $rel = fn (?string $p) => $p ? $e($source['slug'] . '/' . basename($p)) : null;
            $sheetsHtml = '';
            foreach (($source['sheets'] ?? []) as $type => $sheetPath) {
                $title = $type === 'detail' ? 'Detailrijke uitsnede (100%)' : 'Vlak gebied — ruisvergelijk (100%)';
                $sheetsHtml .= '<h4>' . $title . '</h4><img src="' . $rel($sheetPath) . '" alt="contact sheet">';
            }

            $sourcesHtml .= '<section><h2>' . $e($source['slug']) . '</h2>'
                . '<p>' . $source['width'] . 'x' . $source['height'] . ' px — benodigde schaal '
                . $source['required_scale'] . 'x (AI-pass ' . $source['ai_scale'] . 'x)</p>'
                . $gate
                . '<h3>Haalbaarheid per printformaat (na 4x AI-pass)</h3>'
                . '<table><tr><th>Formaat</th><th>Effectieve DPI</th><th>Status</th><th>Aanbieden?</th></tr>' . $feasRows . '</table>'
                . '<h3>Resultaten (winnaar bovenaan)</h3>'
                . '<table><tr><th>#</th><th>Model</th><th>Pre-denoise</th><th>Blend %</th><th>Sharpen</th><th>Score</th>'
                . '<th>Detail</th><th>Rand</th><th>Textuur</th><th>Ruis sd</th></tr>' . $varRows . '</table>'
                . $sheetsHtml
                . '</section>';
        }

        $weights = $results['weights'];
        $weightsStr = implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($weights), $weights));

        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<title>PosterForge upscale-benchmark</title>
<style>
body { font-family: system-ui, sans-serif; background: #0f0f10; color: #e5e5e5; margin: 2rem; }
h1, h2, h3, h4 { color: #fff; }
section { border-top: 1px solid #333; margin-top: 2rem; padding-top: 1rem; }
table { border-collapse: collapse; margin: .75rem 0 1.5rem; font-size: .9rem; }
th, td { border: 1px solid #333; padding: .35rem .6rem; text-align: left; }
th { background: #1c1c1e; }
tr.winner td { background: #14331a; }
tr.ok td:nth-child(4) { color: #7bd88f; }
tr.warn td:nth-child(4) { color: #e5c07b; }
tr.bad td { color: #e06c75; }
img { max-width: 100%; height: auto; border: 1px solid #333; margin-bottom: 1.5rem; }
.rec { background: #14331a; border: 1px solid #2d6a3a; padding: .75rem 1rem; border-radius: 6px; }
.gate { background: #3a1518; border: 1px solid #7a2c33; padding: .75rem 1rem; border-radius: 6px; }
.note { color: #e5c07b; }
.meta { color: #999; font-size: .85rem; }
</style>
</head>
<body>
<h1>PosterForge upscale-benchmark</h1>
<p class="meta">Run: {$e($results['run_dir'])}<br>
Doel: {$e($results['target_size'])} cm @ {$results['target_dpi']} DPI ({$results['target_box']['width']}x{$results['target_box']['height']} px)<br>
Weging: {$e($weightsStr)} — ratio's zijn t.o.v. de bicubic-baseline op hetzelfde doelformaat.<br>
Gestart {$e($results['started_at'])}, klaar {$e($results['finished_at'])}</p>
{$recHtml}
<h2>Overall ranking (gemiddeld over alle bronnen)</h2>
<table><tr><th>#</th><th>Model</th><th>Pre-denoise</th><th>Blend %</th><th>Sharpen</th><th>Gem. score</th></tr>
{$rows}
</table>
{$sourcesHtml}
</body>
</html>
HTML;
    }
}
