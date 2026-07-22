<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class UpscaleService
{
    public function __construct(
        private MagickService $magick,
        private DenoiseService $denoiser,
        private QualityControlService $qc,
    ) {}

    public function upscale(
        string $inputPath,
        string $outputPath,
        int $scale = 4,
        string $model = 'realesrgan-x4plus',
        int $denoise = 0,
        int $tileSize = 0,
    ): string {
        $binary = $this->getBinaryPath();
        $magick = $this->magick->path();
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
        int $denoise = 0,
        int $sharpen = 20,
        array $colorAdjust = [],
        int $tileSize = 0,
        int $targetDpi = 300,
        ?\Closure $onProgress = null,
    ): string {
        [$origWidth, $origHeight] = $this->getImageDimensions($inputPath);

        // Calculate required scale factor (use the larger dimension ratio)
        $scaleW = $targetWidth / $origWidth;
        $scaleH = $targetHeight / $origHeight;
        $requiredScale = max($scaleW, $scaleH);

        $report = fn (int $percent) => $onProgress ? $onProgress($percent) : null;

        $tempDir = sys_get_temp_dir();
        $currentInput = $inputPath;
        $tempFiles = [];

        // Bron al groot genoeg: de AI-stap (met zijn impliciete
        // ontruising) wordt overgeslagen. Zonder vangnet zou bronruis
        // dan onbehandeld het printbestand in stromen — ontruis daarom
        // eerst als de bron boven de QC-drempel meet, en pas dezelfde
        // milde nabewerking toe als de AI-tak.
        if ($requiredScale <= 1.0) {
            try {
                $report(20);
                $currentInput = $this->denoiseIfNeeded($inputPath, $tempDir, $tempFiles);

                $report(60);
                $preSharpPath = ($sharpen > 0)
                    ? $tempDir . '/upscale_presharp_' . uniqid() . '.png'
                    : $outputPath;
                if ($sharpen > 0) {
                    $tempFiles[] = $preSharpPath;
                }

                $this->resizeToExact($currentInput, $preSharpPath, $targetWidth, $targetHeight, $targetDpi);

                $report(80);

                if ($sharpen > 0) {
                    $this->applySharpen($preSharpPath, $outputPath, $sharpen);
                }

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

        try {
            // Single AI upscale pass (max 4x), then Lanczos resize to target.
            // Multi-pass is too slow: the second AI pass operates on a massive image.
            $passScale = $requiredScale <= 2.0 ? 2 : 4;

            $passOutput = $tempDir . '/upscale_pass_' . uniqid() . '.png';
            $tempFiles[] = $passOutput;

            $report(10);

            $this->upscale(
                $currentInput,
                $passOutput,
                $passScale,
                $model,
                $denoise,
                $tileSize,
            );

            $currentInput = $passOutput;

            $report(70);

            $report(70);

            // Final resize to exact target dimensions with Lanczos
            $preSharpPath = ($sharpen > 0)
                ? $tempDir . '/upscale_presharp_' . uniqid() . '.png'
                : $outputPath;

            if ($sharpen > 0) {
                $tempFiles[] = $preSharpPath;
            }

            $this->resizeToExact($currentInput, $preSharpPath, $targetWidth, $targetHeight, $targetDpi);

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

    /**
     * Vangnet wanneer geen AI-pass draait: meet de bronruis en ontruis
     * (wavelet) zodra die boven de QC-drempel ligt. 'auto' schaalt de
     * sterkte mee met de overschrijding. Geeft het (eventueel ontruisde)
     * pad terug; de bron zelf wordt nooit aangeraakt.
     */
    private function denoiseIfNeeded(string $inputPath, string $tempDir, array &$tempFiles): string
    {
        $cfg = config('posterforge.denoise.when_ai_skipped', []);
        if (! ($cfg['enabled'] ?? true)) {
            return $inputPath;
        }

        $threshold = (float) config('posterforge.qc.noise.acceptable', 3.0);
        $noise = $this->qc->noiseSd($inputPath);
        if ($noise <= $threshold) {
            return $inputPath;
        }

        $configured = $cfg['strength'] ?? 'auto';
        $order = ['light', 'normal', 'strong'];
        $start = $configured === 'auto'
            ? match (true) {
                $noise > $threshold * 4 => 'strong',
                $noise > $threshold * 2 => 'normal',
                default => 'light',
            }
            : $configured;

        $denoised = $tempDir . '/upscale_noai_denoise_' . uniqid() . '.png';
        $tempFiles[] = $denoised;

        // In 'auto' escaleren we zolang de hermeting boven de drempel
        // blijft en er nog een zwaardere stand is — de export-QC is
        // anders alsnog een harde FAIL zonder route naar een schone print.
        $idx = max(0, (int) array_search($start, $order, true));
        while (true) {
            $this->denoiser->denoise($inputPath, $denoised, $order[$idx]);

            if ($configured !== 'auto' || $idx >= count($order) - 1) {
                break;
            }
            if ($this->qc->noiseSd($denoised) <= $threshold) {
                break;
            }
            $idx++;
        }

        return $denoised;
    }

    private function resizeToExact(string $input, string $output, int $width, int $height, int $dpi = 300): void
    {
        // Cover + center-crop: vult de doelbox zonder de aspectratio te
        // vervormen (de oude "!"-resize rekte het beeld uit bij een
        // afwijkende bron-verhouding).
        $result = Process::timeout(120)->run([
            $this->magick->path(), $input,
            '-filter', 'Lanczos',
            '-resize', "{$width}x{$height}^",
            '-gravity', 'center',
            '-extent', "{$width}x{$height}",
            '-density', (string) $dpi,
            '-units', 'PixelsPerInch',
            $output,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Resize failed: ' . $result->errorOutput());
        }
    }

    private function applyColorAdjust(string $input, string $output, array $adjust): void
    {
        $magick = $this->magick->path();

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
        $magick = $this->magick->path();

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
