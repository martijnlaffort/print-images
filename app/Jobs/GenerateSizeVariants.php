<?php

namespace App\Jobs;

use App\Models\Poster;
use App\Models\PosterActivity;
use App\Services\DpiValidator;
use App\Services\ImageFinalizer;
use App\Services\NamingService;
use App\Services\QualityControlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class GenerateSizeVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public Poster $poster,
        public array $sizes,
        public string $outputDir,
        public string $namingPattern = '{title}_{size}.png',
        public ?int $backgroundTaskId = null,
    ) {
        $this->queue = 'export';
    }

    public function handle(
        NamingService $namingService,
        ImageFinalizer $finalizer,
        QualityControlService $qcService,
    ): void {
        set_time_limit(0);

        if (! is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        // QC gate: never export a failing poster silently.
        $gate = $qcService->gateForExport($this->poster);
        if ($gate->verdict === 'fail') {
            PosterActivity::log($this->poster->id, 'export_blocked', [
                'qc_report_id' => $gate->id,
                'reasons' => $gate->reasons,
            ]);

            if ($this->backgroundTaskId) {
                \App\Models\BackgroundTask::find($this->backgroundTaskId)
                    ?->markFailed("Export geblokkeerd door QC (NIET PRINTEN): {$this->poster->title}");
            }

            return;
        }

        // Exports komen uitsluitend van de behandelde upscale-master.
        // Terugvallen op de onbewerkte bron zou ruis/JPEG-artefacten
        // ongefilterd het printbestand in sturen.
        $sourcePath = $this->poster->upscaled_path;
        if (! $sourcePath || ! file_exists($sourcePath)) {
            PosterActivity::log($this->poster->id, 'export_blocked', [
                'reasons' => ['Geen upscale-master: draai eerst de upscale-stap; exporteren vanaf de onbewerkte bron is niet toegestaan.'],
            ]);

            if ($this->backgroundTaskId) {
                \App\Models\BackgroundTask::find($this->backgroundTaskId)
                    ?->markFailed("Export geblokkeerd (geen upscale-master): {$this->poster->title}");
            }

            return;
        }

        $dpiValidator = new DpiValidator();

        foreach ($this->sizes as $sizeName) {
            $pixels = $dpiValidator->pixelsAt300Dpi($sizeName);
            if (! $pixels) {
                continue;
            }

            // Print exports are always PNG — no JPEG in the print chain.
            $filename = preg_replace('/\.\w+$/', '.png', $namingService->sizeVariantName($this->poster->slug, $sizeName));
            $outputPath = rtrim($this->outputDir, '/\\') . '/' . $filename;

            $finalizer->exportPrintFile($sourcePath, $outputPath, $pixels['width'], $pixels['height'], 300);

            // Harde poort: volledige QC (incl. ruisdrempel) op elke
            // eind-export — een te ruizig of niet-print-klaar bestand
            // laat de taak expliciet falen.
            $report = $qcService->runAndStore($outputPath, 'export', $this->poster->id, requirePrintReady: true);
            if ($report->verdict === 'fail') {
                throw new RuntimeException(
                    "Export {$sizeName} niet print-klaar: " . implode(' ', $report->reasons)
                );
            }
        }

        PosterActivity::log($this->poster->id, 'exported', [
            'sizes' => $this->sizes,
        ]);

        if ($this->backgroundTaskId) {
            \App\Models\BackgroundTask::find($this->backgroundTaskId)
                ?->incrementCompleted();
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($this->backgroundTaskId) {
            \App\Models\BackgroundTask::find($this->backgroundTaskId)
                ?->markFailed($e->getMessage());
        }
    }
}
