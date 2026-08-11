<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Door de app beheerde Python-omgeving voor printqc.py. Maakt bij eerste
 * gebruik zelf een venv aan onder storage en installeert pillow + numpy —
 * de gebruiker hoeft nooit handmatig pip te draaien. Vereist alleen dat
 * er érgens een Python 3 op het systeem staat (python.org of Microsoft
 * Store); ontbreekt die, dan faalt dit met een duidelijke melding en
 * wordt de export als "handmatig beoordelen" gemarkeerd — nooit
 * stilzwijgend vrijgegeven.
 */
class PythonRuntime
{
    private static ?string $verified = null;

    /**
     * Pad naar de venv-Python, klaar voor gebruik (numpy + pillow
     * geverifieerd importeerbaar). Bootstrapt de venv zo nodig.
     */
    public function python(): string
    {
        if (self::$verified !== null && file_exists(self::$verified)) {
            return self::$verified;
        }

        $python = $this->venvPython();

        if (! file_exists($python)) {
            $this->locked(function () use ($python) {
                if (! file_exists($python)) {
                    $this->bootstrap();
                }
            });
        }

        $check = Process::timeout(120)->run([$python, '-c', 'import numpy, PIL']);
        if ($check->failed()) {
            // Venv bestaat maar is stuk (halve install, Python-upgrade):
            // één keer opnieuw opbouwen voordat we opgeven.
            $this->locked(fn () => $this->bootstrap(force: true));

            $check = Process::timeout(120)->run([$python, '-c', 'import numpy, PIL']);
            if ($check->failed()) {
                throw new RuntimeException(
                    'Python-omgeving voor printqc is stuk (numpy/pillow niet importeerbaar): '
                    . trim($check->errorOutput())
                );
            }
        }

        return self::$verified = $python;
    }

    public function venvDir(): string
    {
        return storage_path('app/printqc-venv');
    }

    /**
     * De upscale- en export-worker kunnen allebei als eerste printqc
     * nodig hebben; zonder slot bouwen ze tegelijk dezelfde venv op en
     * corrumperen ze elkaars pip-install.
     */
    private function locked(callable $fn): void
    {
        $lock = Cache::lock('printqc-venv-bootstrap', 900);
        $lock->block(900);
        try {
            $fn();
        } finally {
            $lock->release();
        }
    }

    private function venvPython(): string
    {
        return $this->venvDir() . (PHP_OS_FAMILY === 'Windows' ? '/Scripts/python.exe' : '/bin/python');
    }

    private function bootstrap(bool $force = false): void
    {
        $venv = $this->venvDir();

        if ($force && is_dir($venv)) {
            $this->removeDir($venv);
        }

        $base = $this->basePythonCmd();

        $create = Process::timeout(300)->run([...$base, '-m', 'venv', $venv]);
        if ($create->failed()) {
            throw new RuntimeException(
                'Kon geen Python-venv aanmaken voor printqc: ' . trim($create->errorOutput() ?: $create->output())
            );
        }

        $install = Process::timeout(900)->run([
            $this->venvPython(), '-m', 'pip', 'install',
            '--disable-pip-version-check', '--no-input',
            'numpy', 'pillow',
        ]);
        if ($install->failed()) {
            throw new RuntimeException(
                'Kon numpy/pillow niet installeren in de printqc-venv (internet nodig bij eerste keer): '
                . trim($install->errorOutput())
            );
        }

        $versions = Process::timeout(60)->run([
            $this->venvPython(), '-c', 'import numpy, PIL, sys; print(sys.version.split()[0], numpy.__version__, PIL.__version__)',
        ]);
        \Log::info('printqc-venv opgebouwd', ['python numpy pillow' => trim($versions->output())]);
    }

    /**
     * Basis-Python om de venv mee te maken. Als lijst, omdat de Windows
     * py-launcher een extra argument nodig heeft (py -3).
     */
    private function basePythonCmd(): array
    {
        $configured = config('posterforge.printqc.python');
        if ($configured) {
            return [$configured];
        }

        foreach ([['python'], ['python3'], ['py', '-3']] as $candidate) {
            $probe = Process::timeout(30)->run([...$candidate, '--version']);
            if ($probe->successful() && str_contains($probe->output() . $probe->errorOutput(), 'Python 3')) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Geen Python 3 gevonden. Installeer Python (python.org of Microsoft Store) '
            . 'of zet PRINTQC_PYTHON in .env naar een python.exe.'
        );
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
