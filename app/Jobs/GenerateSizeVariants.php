<?php

namespace App\Jobs;

use App\Models\Poster;
use App\Models\PosterActivity;
use App\Services\DpiValidator;
use App\Services\ImageFinalizer;
use App\Services\NamingService;
use App\Services\PrintQcService;
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
        PrintQcService $printQc,
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

        $blockedSizes = [];
        $reviewSizes = [];

        foreach ($this->sizes as $sizeName) {
            $pixels = $dpiValidator->pixelsAt300Dpi($sizeName);
            if (! $pixels) {
                continue;
            }

            // Print exports are always PNG — no JPEG in the print chain.
            $filename = preg_replace('/\.\w+$/', '.png', $namingService->sizeVariantName($this->poster->slug, $sizeName));
            $outputPath = rtrim($this->outputDir, '/\\') . '/' . $filename;

            $finalizer->exportPrintFile($sourcePath, $outputPath, $pixels['width'], $pixels['height'], 300);

            // Harde poort 1: volledige app-QC op harde criteria (modus,
            // ICC, PNG). Het al geschreven bestand wordt eerst
            // geblokkeerd/hernoemd, daarna faalt de taak expliciet —
            // nooit een afgekeurd bestand onbewaakt laten staan.
            $report = $qcService->runAndStore($outputPath, 'export', $this->poster->id, requirePrintReady: true);
            if ($report->verdict === 'fail') {
                $printQc->blockFailedPrintReady($this->poster, $outputPath, $sizeName, $report->reasons);
                throw new RuntimeException(
                    "Export {$sizeName} niet print-klaar: " . implode(' ', $report->reasons)
                );
            }

            // Harde poort 2 (de laatste vóór "klaar voor Gelato"):
            // printqc.py. Exit 2 blokkeert en hernoemt het bestand,
            // exit 1 markeert het als handmatig beoordelen. Bewust géén
            // exception: de andere formaten/posters op dezelfde
            // achtergrondtaak moeten gewoon doorlopen en de eindtoast
            // telt de uitslag per bestand uit export_files.
            $exportFile = $printQc->guardExport($this->poster, $outputPath, $sizeName);
            if ($exportFile->status === 'blocked') {
                $blockedSizes[] = $sizeName;
            } elseif ($exportFile->status === 'review') {
                $reviewSizes[] = $sizeName;
            }
        }

        PosterActivity::log($this->poster->id, 'exported', [
            'sizes' => $this->sizes,
            'geblokkeerd' => $blockedSizes,
            'handmatig_beoordelen' => $reviewSizes,
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
