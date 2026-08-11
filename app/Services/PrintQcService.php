<?php

namespace App\Services;

use App\Models\ExportFile;
use App\Models\Poster;
use App\Models\PosterActivity;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * De laatste poort vóór "klaar voor Gelato": het gebundelde printqc.py
 * (bin/printqc) keurt elk exportbestand. Exitcode 0 = vrijgeven,
 * 1 = handmatig beoordelen, 2 = blokkeren. Verplicht en niet optioneel;
 * kan de poort zelf niet draaien, dan wordt de export als "handmatig
 * beoordelen" gemarkeerd — nooit stilzwijgend vrijgegeven.
 */
class PrintQcService
{
    public function __construct(
        private PythonRuntime $runtime,
    ) {}

    /**
     * Draait printqc.py op één bestand. De json wordt naast het bestand
     * bewaard (naspeurbaar waarom iets is goedgekeurd); crops en
     * overzichtskaart komen in een rapportmap ernaast.
     *
     * @return array{exit:int, rapport:array, json_path:string, report_dir:?string}
     */
    public function inspect(string $filePath, ?string $sizeName = null): array
    {
        $script = base_path('bin/printqc/printqc.py');
        if (! file_exists($script)) {
            throw new RuntimeException("printqc.py niet gevonden: {$script}");
        }

        $jsonPath = preg_replace('/\.png$/i', '', $filePath) . '.printqc.json';
        $reportDir = dirname($filePath) . DIRECTORY_SEPARATOR . 'printqc-rapport'
            . DIRECTORY_SEPARATOR . pathinfo($filePath, PATHINFO_FILENAME);

        // Een achtergebleven json van een eerdere run mag nooit als verse
        // uitslag gelezen worden wanneer de nieuwe run crasht zonder json
        // te schrijven.
        @unlink($jsonPath);

        $cmd = [
            $this->runtime->python(), $script, $filePath,
            '--json', $jsonPath,
            '--rapport', $reportDir,
            '--geen-kleur',
        ];

        // Doelformaat altijd expliciet meegeven als het script het kent —
        // de bestandsnaam-detectie kan misgokken op cijfers in de slug.
        if ($sizeName && in_array($sizeName, config('posterforge.printqc.formats', []), true)) {
            $cmd[] = '--formaat';
            $cmd[] = $sizeName;
        }

        $result = Process::timeout((int) config('posterforge.printqc.timeout', 900))->run($cmd);
        $exit = $result->exitCode();

        // Een Python-crash (traceback, ontbrekende dependency) geeft óók
        // exit 1/2 maar schrijft geen json — daarom is de json de
        // waarheidsbron, niet alleen de exitcode.
        $rapport = null;
        if (file_exists($jsonPath)) {
            $all = json_decode((string) file_get_contents($jsonPath), true);
            $rapport = is_array($all) ? ($all[0] ?? null) : null;
        }

        if (! in_array($exit, [0, 1, 2], true) || ! is_array($rapport)) {
            throw new RuntimeException(sprintf(
                'printqc kon niet draaien (exit %s): %s',
                $exit ?? '?',
                substr(trim($result->errorOutput() ?: $result->output()), -500),
            ));
        }

        return [
            'exit' => $exit,
            'rapport' => $rapport,
            'json_path' => $jsonPath,
            'report_dir' => is_dir($reportDir) ? $reportDir : null,
        ];
    }

    /**
     * De verplichte poort ná elke export-write: keurt het bestand, slaat
     * de uitslag op als ExportFile en handhaaft haar: geblokkeerde
     * bestanden worden hernoemd naar *_GEBLOKKEERD.png zodat ze nooit
     * per ongeluk naar Gelato gaan.
     */
    public function guardExport(Poster $poster, string $outputPath, string $sizeName): ExportFile
    {
        try {
            $qc = $this->inspect($outputPath, $sizeName);
        } catch (\Throwable $e) {
            \Log::warning('printqc kon niet draaien', ['bestand' => $outputPath, 'fout' => $e->getMessage()]);

            $file = ExportFile::create([
                'poster_id' => $poster->id,
                'size' => $sizeName,
                'path' => $outputPath,
                'status' => 'review',
                'findings' => [[
                    'niveau' => 'REVIEW',
                    'check' => 'printqc',
                    'tekst' => 'printqc kon niet draaien — handmatig beoordelen: ' . $e->getMessage(),
                ]],
                'md5' => md5_file($outputPath) ?: null,
            ]);

            PosterActivity::log($poster->id, 'export_review', [
                'size' => $sizeName,
                'reasons' => ['printqc kon niet draaien: ' . $e->getMessage()],
            ]);

            return $file;
        }

        $rapport = $qc['rapport'];
        $status = match ($qc['exit']) {
            0 => 'released',
            1 => 'review',
            2 => 'blocked',
        };

        $findings = $rapport['bevindingen'] ?? [];
        $path = $outputPath;
        if ($status === 'blocked') {
            [$path, $renamed] = $this->moveToBlocked($outputPath);
            if (! $renamed) {
                array_unshift($findings, [
                    'niveau' => 'FAIL',
                    'check' => 'hernoemen',
                    'tekst' => 'LET OP: hernoemen naar *_GEBLOKKEERD.png is MISLUKT (bestand in gebruik?) — het afgekeurde bestand staat nog onder zijn normale naam: ' . basename($outputPath),
                ]);
            }
        }

        $file = ExportFile::create([
            'poster_id' => $poster->id,
            'size' => $sizeName,
            'path' => $path,
            'status' => $status,
            'printqc_exit' => $qc['exit'],
            'printqc_status' => $rapport['status'] ?? null,
            'findings' => $findings,
            'meta' => $rapport['meta'] ?? null,
            'json_path' => $qc['json_path'],
            'report_dir' => $qc['report_dir'],
            'md5' => $rapport['md5'] ?? null,
        ]);

        $reasons = array_map(
            fn ($b) => "[{$b['niveau']}] {$b['check']}: {$b['tekst']}",
            $findings,
        );

        if ($status === 'blocked') {
            PosterActivity::log($poster->id, 'export_blocked', [
                'size' => $sizeName,
                'door' => 'printqc',
                'reasons' => $reasons,
            ]);
        } elseif ($status === 'review') {
            PosterActivity::log($poster->id, 'export_review', [
                'size' => $sizeName,
                'reasons' => $reasons,
            ]);
        }

        return $file;
    }

    /**
     * Blokkeert een al geschreven exportbestand dat door de app-QC
     * (poort 1: modus/ICC/PNG) is afgekeurd, zodat het niet onbewaakt
     * onder zijn gewone printnaam in de exportmap blijft staan.
     */
    public function blockFailedPrintReady(Poster $poster, string $outputPath, string $sizeName, array $reasons): ExportFile
    {
        [$path, $renamed] = $this->moveToBlocked($outputPath);

        $findings = array_map(
            fn ($r) => ['niveau' => 'FAIL', 'check' => 'app-qc', 'tekst' => $r],
            $reasons,
        );
        if (! $renamed) {
            array_unshift($findings, [
                'niveau' => 'FAIL',
                'check' => 'hernoemen',
                'tekst' => 'LET OP: hernoemen naar *_GEBLOKKEERD.png is MISLUKT (bestand in gebruik?) — het afgekeurde bestand staat nog onder zijn normale naam: ' . basename($outputPath),
            ]);
        }

        $file = ExportFile::create([
            'poster_id' => $poster->id,
            'size' => $sizeName,
            'path' => $path,
            'status' => 'blocked',
            'findings' => $findings,
            'md5' => md5_file($path) ?: null,
        ]);

        PosterActivity::log($poster->id, 'export_blocked', [
            'size' => $sizeName,
            'door' => 'app-qc',
            'reasons' => $reasons,
        ]);

        return $file;
    }

    /**
     * Hernoemt (of desnoods kopieert+verwijdert) een afgekeurd bestand
     * naar *_GEBLOKKEERD.png. Retourneert [uiteindelijk pad, gelukt?] —
     * een mislukking wordt nooit stil geslikt: de aanroeper zet er een
     * FAIL-bevinding bij en dit logt een error.
     */
    private function moveToBlocked(string $outputPath): array
    {
        $blockedPath = preg_replace('/\.png$/i', '', $outputPath) . '_GEBLOKKEERD.png';

        if (file_exists($blockedPath)) {
            @unlink($blockedPath);
        }

        $moved = @rename($outputPath, $blockedPath)
            || (@copy($outputPath, $blockedPath) && @unlink($outputPath));

        if (! $moved) {
            \Log::error('printqc: kon geblokkeerd bestand niet hernoemen — het staat nog onder zijn normale naam in de exportmap', [
                'bestand' => $outputPath,
            ]);

            return [$outputPath, false];
        }

        return [$blockedPath, true];
    }
}
