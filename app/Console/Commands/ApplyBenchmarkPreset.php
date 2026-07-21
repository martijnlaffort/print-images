<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

class ApplyBenchmarkPreset extends Command
{
    protected $signature = 'posterforge:benchmark-apply
        {run? : Run-id (bv. 20260721_081312); default: de laatste run}
        {--rank=1 : Welke plek uit de overall ranking toepassen}';

    protected $description = 'Slaat de aanbevolen benchmark-configuratie op als preset; de upscale-pagina\'s gebruiken die als handmatige defaults';

    public function handle(): int
    {
        $base = storage_path('app' . DIRECTORY_SEPARATOR . config('posterforge.benchmark.output_dir', 'benchmark'));

        $runId = $this->argument('run');
        if (! $runId) {
            $runs = collect(glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR))->sort()->values();
            if ($runs->isEmpty()) {
                $this->error("Geen benchmark-runs gevonden in {$base}. Draai eerst posterforge:benchmark.");
                return self::FAILURE;
            }
            $runId = basename($runs->last());
        }

        $jsonPath = $base . DIRECTORY_SEPARATOR . $runId . DIRECTORY_SEPARATOR . 'results.json';
        if (! file_exists($jsonPath)) {
            $this->error("Geen results.json in run {$runId}.");
            return self::FAILURE;
        }

        $results = json_decode(file_get_contents($jsonPath), true);
        $ranking = $results['aggregate']['ranking'] ?? [];
        $rank = max(1, (int) $this->option('rank'));

        if (! isset($ranking[$rank - 1])) {
            $this->error("Ranking-positie {$rank} bestaat niet (run heeft " . count($ranking) . ' configuraties).');
            return self::FAILURE;
        }

        $entry = $ranking[$rank - 1];
        $preset = [
            ...$entry['config'],
            'benchmark_run' => $runId,
            'mean_score' => $entry['mean_score'],
        ];

        Setting::set('upscale.preset', $preset);

        $this->info("Preset opgeslagen uit run {$runId} (positie {$rank}):");
        $this->table(['Model', 'Pre-denoise', 'Blend %', 'Sharpen', 'Gem. score'], [[
            $preset['model'],
            $preset['pre_denoise'],
            $preset['blend_bicubic'],
            $preset['sharpen'],
            number_format($preset['mean_score'], 3),
        ]]);
        $this->line('De Upscale- en Pipeline-pagina\'s gebruiken deze preset nu als handmatige defaults (automatische modus kiest nog steeds per afbeelding).');

        return self::SUCCESS;
    }
}
