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
        string $model = 'realesrgan-x4plus',
        int $denoise = 50,
    ): string {
        $binary = $this->getBinaryPath();
        $magick = $this->getMagickPath();
        $tempDir = sys_get_temp_dir();
        $aiOutput = $tempDir . '/upscale_ai_' . uniqid() . '.png';

        try {
            // Step 1: AI upscale
            $result = Process::timeout(300)->run([
                $binary,
                '-i', $inputPath,
                '-o', $denoise > 0 ? $aiOutput : $outputPath,
                '-s', (string) $scale,
                '-n', $model,
                '-f', 'png',
            ]);

            if ($result->failed()) {
                throw new RuntimeException(
                    'Upscale failed: ' . $result->errorOutput()
                );
            }

            // Step 2: Blend with bicubic upscale to reduce AI artifacts
            if ($denoise > 0) {
                $bicubicOutput = $tempDir . '/upscale_bicubic_' . uniqid() . '.png';

                try {
                    // Create bicubic upscale of original at the same scale
                    $percent = $scale * 100;
                    $bicubicResult = Process::timeout(60)->run([
                        $magick, $inputPath,
                        '-resize', "{$percent}%",
                        $bicubicOutput,
                    ]);

                    if ($bicubicResult->failed()) {
                        throw new RuntimeException(
                            'Bicubic upscale failed: ' . $bicubicResult->errorOutput()
                        );
                    }

                    // Blend: (100 - denoise)% AI + denoise% bicubic
                    $aiWeight = 100 - $denoise;
                    $blendResult = Process::timeout(60)->run([
                        $magick, 'composite',
                        '-blend', "{$aiWeight}",
                        $aiOutput, $bicubicOutput,
                        $outputPath,
                    ]);

                    if ($blendResult->failed()) {
                        throw new RuntimeException(
                            'Blend failed: ' . $blendResult->errorOutput()
                        );
                    }
                } finally {
                    @unlink($bicubicOutput);
                }
            }

            return $outputPath;
        } finally {
            if ($denoise > 0) {
                @unlink($aiOutput);
            }
        }
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

    private function getMagickPath(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'C:\\Program Files\\ImageMagick-7.1.2-Q16\\magick.exe',
            default => 'magick',
        };
    }
}
