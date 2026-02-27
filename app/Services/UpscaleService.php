<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class UpscaleService
{
    public function upscale(
        string $inputPath,
        string $outputPath,
        int $scale = 4,
        string $model = 'realesrgan-x4plus'
    ): string {
        $binary = $this->getBinaryPath();

        $result = Process::timeout(300)->run([
            $binary,
            '-i', $inputPath,
            '-o', $outputPath,
            '-s', (string) $scale,
            '-n', $model,
            '-f', 'png',
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Upscale failed: ' . $result->errorOutput()
            );
        }

        return $outputPath;
    }

    public function batchUpscale(
        string $inputDir,
        string $outputDir,
        int $scale = 4,
        string $model = 'realesrgan-x4plus'
    ): string {
        $binary = $this->getBinaryPath();

        $result = Process::timeout(3600)->run([
            $binary,
            '-i', $inputDir,
            '-o', $outputDir,
            '-s', (string) $scale,
            '-n', $model,
            '-f', 'png',
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Batch upscale failed: ' . $result->errorOutput()
            );
        }

        return $outputDir;
    }

    public function getBinaryPath(): string
    {
        $platform = PHP_OS_FAMILY;

        return match ($platform) {
            'Darwin' => base_path('bin/mac/realesrgan-ncnn-vulkan'),
            'Windows' => base_path('bin/win/realesrgan-ncnn-vulkan.exe'),
            'Linux' => base_path('bin/linux/realesrgan-ncnn-vulkan'),
            default => throw new RuntimeException("Unsupported platform: {$platform}"),
        };
    }

    public function isAvailable(): bool
    {
        return file_exists($this->getBinaryPath());
    }
}
