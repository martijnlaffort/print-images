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
        int $tileSize = 0,
    ): string {
        $binary = $this->getBinaryPath();
        $magick = $this->getMagickPath();
        $tempDir = sys_get_temp_dir();
        $aiOutput = $tempDir . '/upscale_ai_' . uniqid() . '.png';

        try {
            // Step 1: AI upscale
            $cmd = [
                $binary,
                '-i', $inputPath,
                '-o', $denoise > 0 ? $aiOutput : $outputPath,
                '-s', (string) $scale,
                '-n', $model,
                '-f', 'png',
            ];

            if ($tileSize > 0) {
                $cmd[] = '-t';
                $cmd[] = (string) $tileSize;
            }

            $result = Process::timeout(300)->run($cmd);

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
                        '-dissolve', "{$aiWeight}",
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

    /**
     * Smart upscale: target specific pixel dimensions with multi-pass and post-processing.
     */
    public function smartUpscale(
        string $inputPath,
        string $outputPath,
        int $targetWidth,
        int $targetHeight,
        string $model = 'realesrgan-x4plus',
        int $denoise = 50,
        int $sharpen = 0,
        array $colorAdjust = [],
        int $tileSize = 0,
        ?\Closure $onProgress = null,
    ): string {
        $magick = $this->getMagickPath();
        [$origWidth, $origHeight] = $this->getImageDimensions($inputPath);

        // Calculate required scale factor (use the larger dimension ratio)
        $scaleW = $targetWidth / $origWidth;
        $scaleH = $targetHeight / $origHeight;
        $requiredScale = max($scaleW, $scaleH);

        $report = fn (int $percent) => $onProgress ? $onProgress($percent) : null;

        // If image already meets target, just resize to exact dimensions
        if ($requiredScale <= 1.0) {
            $report(80);
            $this->resizeToExact($inputPath, $outputPath, $targetWidth, $targetHeight);
            return $outputPath;
        }

        $tempDir = sys_get_temp_dir();
        $currentInput = $inputPath;
        $tempFiles = [];

        try {
            // Multi-pass upscaling: Real-ESRGAN supports max 4x per pass
            $remainingScale = $requiredScale;
            $pass = 0;

            // Count total passes needed for progress calculation
            $tempRemaining = $requiredScale;
            $totalPasses = 0;
            while ($tempRemaining > 1.0) {
                $s = min(4, (int) ceil($tempRemaining));
                if ($s < 2) $s = 2;
                if ($s > 2 && $s < 4) $s = 4;
                $tempRemaining /= $s;
                $totalPasses++;
            }

            while ($remainingScale > 1.0) {
                $passScale = min(4, (int) ceil($remainingScale));
                // For small remaining scales (1.x - 2.x), still use 4x then resize down
                // This produces better quality than trying fractional scales
                if ($passScale < 2) {
                    $passScale = 2;
                }
                // Real-ESRGAN only supports 4x and 2x for most models
                if ($passScale > 2 && $passScale < 4) {
                    $passScale = 4;
                }

                $passOutput = $tempDir . '/upscale_pass_' . $pass . '_' . uniqid() . '.png';
                $tempFiles[] = $passOutput;

                $isLastPass = ($remainingScale / $passScale) <= 1.0;

                // Progress: upscale passes span 0-70% of the work
                $report((int) (($pass / $totalPasses) * 70));

                $this->upscale(
                    $currentInput,
                    $passOutput,
                    $passScale,
                    $model,
                    $isLastPass ? $denoise : 0, // Only blend on final pass
                    $tileSize,
                );

                $currentInput = $passOutput;
                $remainingScale = $remainingScale / $passScale;
                $pass++;

                $report((int) (($pass / $totalPasses) * 70));
            }

            $report(70);

            // Final resize to exact target dimensions with Lanczos
            $preSharpPath = ($sharpen > 0)
                ? $tempDir . '/upscale_presharp_' . uniqid() . '.png'
                : $outputPath;

            if ($sharpen > 0) {
                $tempFiles[] = $preSharpPath;
            }

            $this->resizeToExact($currentInput, $preSharpPath, $targetWidth, $targetHeight);

            $report(80);

            // Optional sharpening
            if ($sharpen > 0) {
                $this->applySharpen($preSharpPath, $outputPath, $sharpen);
            }

            $report(90);

            // Optional color adjustment
            if (! empty($colorAdjust)) {
                $this->applyColorAdjust($outputPath, $outputPath, $colorAdjust);
            }

            return $outputPath;
        } finally {
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
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

    public function getImageDimensions(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException("Cannot read image dimensions: {$path}");
        }

        return [(int) $info[0], (int) $info[1]];
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
        $configured = config('posterforge.imagemagick_path');
        if ($configured) {
            return $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Search common installation directories
            $globPattern = 'C:\\Program Files\\ImageMagick-*\\magick.exe';
            $matches = glob($globPattern);
            if (!empty($matches)) {
                return $matches[0];
            }

            throw new RuntimeException(
                'ImageMagick not found. Install it from https://imagemagick.org/script/download.php#windows '
                . 'or set IMAGEMAGICK_PATH in your .env file.'
            );
        }

        return 'magick';
    }

    private function resizeToExact(string $input, string $output, int $width, int $height): void
    {
        $magick = $this->getMagickPath();

        $result = Process::timeout(120)->run([
            $magick, $input,
            '-filter', 'Lanczos',
            '-resize', "{$width}x{$height}!",
            '-density', '300',
            '-units', 'PixelsPerInch',
            $output,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Resize failed: ' . $result->errorOutput());
        }
    }

    private function applyColorAdjust(string $input, string $output, array $adjust): void
    {
        $magick = $this->getMagickPath();

        $brightness = $adjust['brightness'] ?? 100;
        $contrast = $adjust['contrast'] ?? 0;
        $saturation = $adjust['saturation'] ?? 100;

        $cmd = [$magick, $input];

        // Brightness and saturation via -modulate (brightness, saturation, hue)
        if ($brightness !== 100 || $saturation !== 100) {
            $cmd[] = '-modulate';
            $cmd[] = "{$brightness},{$saturation},100";
        }

        // Contrast via -brightness-contrast
        if ($contrast !== 0) {
            $cmd[] = '-brightness-contrast';
            $cmd[] = "0x{$contrast}";
        }

        $cmd[] = $output;

        $result = Process::timeout(120)->run($cmd);
        if ($result->failed()) {
            throw new RuntimeException('Color adjust failed: ' . $result->errorOutput());
        }
    }

    private function applySharpen(string $input, string $output, int $strength): void
    {
        $magick = $this->getMagickPath();

        // USM: radiusxsigma+gain+threshold
        // Scale strength 1-100 to reasonable USM values
        $sigma = round(0.5 + ($strength / 100) * 1.0, 2); // 0.5 - 1.5
        $gain = round(0.5 + ($strength / 100) * 1.0, 2);  // 0.5 - 1.5
        $threshold = '0.02';

        $result = Process::timeout(120)->run([
            $magick, $input,
            '-unsharp', "0x{$sigma}+{$gain}+{$threshold}",
            $output,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Sharpen failed: ' . $result->errorOutput());
        }
    }
}
