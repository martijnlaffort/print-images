<?php

namespace App\Console\Commands;

use App\Services\Benchmark\BenchmarkReport;
use App\Services\Benchmark\BenchmarkRunner;
use Illuminate\Console\Command;

class RunBenchmark extends Command
{
    protected $signature = 'posterforge:benchmark
        {input? : Map met bronafbeeldingen (default: public/test_images)}
        {--target= : Doelformaat, bv. 50x70 (default uit config)}
        {--dpi= : Doel-DPI (default uit config)}
        {--models= : Komma-lijst modellen (override van de as)}
        {--pre-denoise= : Komma-lijst pre-denoise standen (off,light,normal,strong)}
        {--blend= : Komma-lijst blend-percentages (bv. 0,25)}
        {--sharpen= : Komma-lijst sharpen-sterktes (bv. 0,20)}
        {--run= : Bestaande run-id hervatten (hergebruikt gecachte AI-passes)}
        {--yes : Start zonder bevestiging}';

    protected $description = 'Draait de upscale-benchmark-matrix op een map bronafbeeldingen en genereert contact-sheets + scorerapport';

    public function handle(BenchmarkRunner $runner, BenchmarkReport $report): int
    {
        $input = $this->argument('input') ?: base_path('public/test_images');
        if (! is_dir($input)) {
            $this->error("Bronmap niet gevonden: {$input}");
            return self::FAILURE;
        }

        $sources = collect(glob($input . DIRECTORY_SEPARATOR . '*'))
            ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff']))
            ->values()
            ->all();

        if (! $sources) {
            $this->error("Geen afbeeldingen gevonden in: {$input}");
            return self::FAILURE;
        }

        $cfg = config('posterforge.benchmark');
        $targetSize = $this->option('target') ?: $cfg['target_size'];
        $targetDpi = (int) ($this->option('dpi') ?: $cfg['target_dpi']);

        $axes = $cfg['axes'];
        $given = fn (string $name) => $this->option($name) !== null && $this->option($name) !== '';
        if ($given('models')) {
            $axes['model'] = array_map('trim', explode(',', $this->option('models')));
        }
        if ($given('pre-denoise')) {
            $axes['pre_denoise'] = array_map('trim', explode(',', $this->option('pre-denoise')));
        }
        if ($given('blend')) {
            $axes['blend_bicubic'] = array_map('intval', explode(',', $this->option('blend')));
        }
        if ($given('sharpen')) {
            $axes['sharpen'] = array_map('intval', explode(',', $this->option('sharpen')));
        }

        // ── Vooraf: omvang van de run melden ──
        $plan = $runner->plan($sources, $axes, $targetSize, $targetDpi);

        $this->info('Benchmark-plan');
        $this->table(['Bron', 'Afmetingen'], array_map(
            fn ($s) => [basename($s['path']), "{$s['width']}x{$s['height']}"],
            $plan['sources'],
        ));
        $this->line("Doel: {$targetSize} cm @ {$targetDpi} DPI");
        $this->line('Assen: model=' . implode('|', $axes['model'])
            . '  pre_denoise=' . implode('|', $axes['pre_denoise'])
            . '  blend=' . implode('|', $axes['blend_bicubic'])
            . '  sharpen=' . implode('|', $axes['sharpen']));
        $this->line("AI-passes: {$plan['ai_passes']}  |  varianten totaal: {$plan['variants']}");
        $this->line("Ruwe schatting GPU-tijd: ±{$plan['estimated_ai_minutes']} min (excl. metingen)");

        if (! $this->option('yes') && ! $this->confirm('Starten?', true)) {
            return self::SUCCESS;
        }

        $runId = $this->option('run') ?: now()->format('Ymd_His');
        $runDir = storage_path('app' . DIRECTORY_SEPARATOR . $cfg['output_dir'] . DIRECTORY_SEPARATOR . $runId);
        if (! is_dir($runDir)) {
            mkdir($runDir, 0755, true);
        }

        $started = microtime(true);
        $results = $runner->run($sources, $axes, $targetSize, $targetDpi, $runDir,
            fn (string $msg) => $this->line('  ' . $msg));

        $this->info('Rapport genereren...');
        $out = $report->generate($results);
        $minutes = round((microtime(true) - $started) / 60, 1);

        // ── Samenvatting ──
        $this->newLine();
        $this->info("Klaar in {$minutes} min.");

        foreach ($out['aggregate']['per_source'] as $slug => $ps) {
            $this->line("• {$slug}: winnaar {$ps['winner']} (score " . number_format($ps['winner_score'], 3) . ')');
            if (! $ps['target_feasible']) {
                $this->warn("  ⚠ NIET aanbieden op {$targetSize} cm ({$ps['target_min_dpi']} DPI); grootste haalbare formaat: "
                    . ($ps['max_sellable_size'] ?? 'geen'));
            }
        }

        if ($rec = $out['aggregate']['recommendation']) {
            $this->newLine();
            $this->info('Aanbeveling (gemiddeld over de testset): ' . $rec['id']
                . ' — score ' . number_format($rec['mean_score'], 3));
        }

        $this->line("Rapport: {$out['html']}");
        $this->line("Data:    {$out['json']}");

        return self::SUCCESS;
    }
}
