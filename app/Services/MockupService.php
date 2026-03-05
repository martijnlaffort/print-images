<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class MockupService
{
    const FRAME_PRESETS = [
        'none' => 'No Frame',
        'thin-black' => 'Thin Black',
        'thin-white' => 'Thin White',
        'gallery-white' => 'Gallery White (Wide)',
        'oak-wood' => 'Oak Wood',
        'dark-wood' => 'Dark Wood',
    ];

    private function getMagickPath(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'C:\\Program Files\\ImageMagick-7.1.2-Q16\\magick.exe',
            default => 'magick',
        };
    }

    public function generate(
        string $posterPath,
        string $backgroundPath,
        array $corners,
        string $outputPath,
        array $options = []
    ): string {
        $magick = $this->getMagickPath();
        $tempDir = sys_get_temp_dir();

        $fitMode = $options['fitMode'] ?? 'fill';
        $format = $options['format'] ?? 'jpg';
        $quality = $options['quality'] ?? 92;
        $framePreset = $options['framePreset'] ?? 'none';
        $textOverlay = $options['text'] ?? null;

        $bgSize = $this->getImageSize($backgroundPath);

        // Calculate the target quad dimensions for fit/fill
        $preparedPath = $this->preparePoster($posterPath, $corners, $fitMode, $tempDir);
        $posterSize = $this->getImageSize($preparedPath);
        $cleanupPrepared = ($preparedPath !== $posterPath);

        $distortedPath = $tempDir . '/mockup_distorted_' . uniqid() . '.png';

        try {
            $posterW = $posterSize[0];
            $posterH = $posterSize[1];
            $perspectivePoints = implode('  ', [
                "0,0,{$corners[0]['x']},{$corners[0]['y']}",
                "{$posterW},0,{$corners[1]['x']},{$corners[1]['y']}",
                "{$posterW},{$posterH},{$corners[2]['x']},{$corners[2]['y']}",
                "0,{$posterH},{$corners[3]['x']},{$corners[3]['y']}",
            ]);

            $distortCmd = [
                $magick,
                $preparedPath,
                '-alpha', 'set',
                '-virtual-pixel', 'transparent',
                '-background', 'none',
                '-distort', 'Perspective', $perspectivePoints,
                '-gravity', 'NorthWest',
                '-extent', "{$bgSize[0]}x{$bgSize[1]}",
            ];

            if (isset($options['brightness']) && $options['brightness'] !== 100) {
                $distortCmd[] = '-modulate';
                $distortCmd[] = "{$options['brightness']},100,100";
            }

            $distortCmd[] = $distortedPath;
            $this->runMagick($distortCmd);

            // Composite distorted poster onto background
            $compositeCmd = [
                $magick,
                $backgroundPath,
                $distortedPath, '-compose', 'Over', '-composite',
            ];

            // Shadow overlay
            if (! empty($options['shadowPath']) && file_exists($options['shadowPath'])) {
                $compositeCmd = array_merge($compositeCmd, [
                    $options['shadowPath'], '-compose', 'Multiply', '-composite',
                ]);
            }

            // Frame preset or custom frame
            $generatedFramePath = null;
            if ($framePreset !== 'none' && $framePreset !== '') {
                $generatedFramePath = $this->generateFrame($bgSize, $corners, $framePreset, $tempDir);
                if ($generatedFramePath) {
                    $compositeCmd = array_merge($compositeCmd, [
                        $generatedFramePath, '-compose', 'Over', '-composite',
                    ]);
                }
            } elseif (! empty($options['framePath']) && file_exists($options['framePath'])) {
                $compositeCmd = array_merge($compositeCmd, [
                    $options['framePath'], '-compose', 'Over', '-composite',
                ]);
            }

            // Text overlay
            if ($textOverlay && ! empty($textOverlay['text'])) {
                $textImgPath = $this->generateTextOverlay($bgSize, $textOverlay, $tempDir);
                if ($textImgPath) {
                    $compositeCmd = array_merge($compositeCmd, [
                        $textImgPath, '-compose', 'Over', '-composite',
                    ]);
                }
            }

            // Output format and quality
            $compositeCmd = array_merge($compositeCmd, [
                '-quality', (string) $quality,
                $outputPath,
            ]);

            $this->runMagick($compositeCmd);

            return $outputPath;
        } finally {
            @unlink($distortedPath);
            if ($cleanupPrepared) {
                @unlink($preparedPath);
            }
            if ($generatedFramePath) {
                @unlink($generatedFramePath);
            }
            if (isset($textImgPath)) {
                @unlink($textImgPath);
            }
        }
    }

    private function preparePoster(string $posterPath, array $corners, string $fitMode, string $tempDir): string
    {
        if ($fitMode === 'stretch') {
            return $posterPath;
        }

        // Calculate quad aspect ratio from corners
        $topWidth = sqrt(
            pow($corners[1]['x'] - $corners[0]['x'], 2) +
            pow($corners[1]['y'] - $corners[0]['y'], 2)
        );
        $leftHeight = sqrt(
            pow($corners[3]['x'] - $corners[0]['x'], 2) +
            pow($corners[3]['y'] - $corners[0]['y'], 2)
        );

        $quadRatio = $topWidth / max($leftHeight, 1);
        $posterSize = $this->getImageSize($posterPath);
        $posterRatio = $posterSize[0] / max($posterSize[1], 1);

        // If ratios are close enough, no adjustment needed
        if (abs($quadRatio - $posterRatio) < 0.05) {
            return $posterPath;
        }

        $magick = $this->getMagickPath();
        $preparedPath = $tempDir . '/mockup_prepared_' . uniqid() . '.png';

        if ($fitMode === 'fill') {
            // Crop to match quad ratio
            $targetW = $posterSize[0];
            $targetH = (int) round($posterSize[0] / $quadRatio);
            if ($targetH > $posterSize[1]) {
                $targetH = $posterSize[1];
                $targetW = (int) round($posterSize[1] * $quadRatio);
            }

            $this->runMagick([
                $magick, $posterPath,
                '-gravity', 'Center',
                '-crop', "{$targetW}x{$targetH}+0+0",
                '+repage',
                $preparedPath,
            ]);
        } else {
            // Fit: letterbox with transparent padding
            $targetW = $posterSize[0];
            $targetH = (int) round($posterSize[0] / $quadRatio);
            if ($targetH < $posterSize[1]) {
                $targetH = $posterSize[1];
                $targetW = (int) round($posterSize[1] * $quadRatio);
            }

            $this->runMagick([
                $magick, $posterPath,
                '-gravity', 'Center',
                '-background', 'white',
                '-extent', "{$targetW}x{$targetH}",
                $preparedPath,
            ]);
        }

        return $preparedPath;
    }

    private function generateFrame(array $bgSize, array $corners, string $preset, string $tempDir): ?string
    {
        $magick = $this->getMagickPath();
        $framePath = $tempDir . '/mockup_frame_' . uniqid() . '.png';

        // Frame properties per preset
        $frameProps = match ($preset) {
            'thin-black' => ['color' => 'black', 'width' => 3, 'inner' => 0],
            'thin-white' => ['color' => 'white', 'width' => 3, 'inner' => 0],
            'gallery-white' => ['color' => 'white', 'width' => 20, 'inner' => 2],
            'oak-wood' => ['color' => '#C4956A', 'width' => 8, 'inner' => 1],
            'dark-wood' => ['color' => '#3E2723', 'width' => 8, 'inner' => 1],
            default => null,
        };

        if (! $frameProps) {
            return null;
        }

        $w = $bgSize[0];
        $h = $bgSize[1];
        $fw = $frameProps['width'];
        $color = $frameProps['color'];

        // Draw frame lines along the quad edges using ImageMagick MVG
        $drawParts = [];

        // Outer frame border
        $drawParts[] = "stroke {$color} stroke-width {$fw} fill none";
        $drawParts[] = sprintf(
            "polygon %d,%d %d,%d %d,%d %d,%d",
            $corners[0]['x'], $corners[0]['y'],
            $corners[1]['x'], $corners[1]['y'],
            $corners[2]['x'], $corners[2]['y'],
            $corners[3]['x'], $corners[3]['y']
        );

        // Inner border line for wood frames
        if ($frameProps['inner'] > 0) {
            $drawParts[] = "stroke black stroke-width {$frameProps['inner']} stroke-opacity 0.3 fill none";
            // Inset slightly
            $inset = $fw / 2 + 1;
            $cx = ($corners[0]['x'] + $corners[1]['x'] + $corners[2]['x'] + $corners[3]['x']) / 4;
            $cy = ($corners[0]['y'] + $corners[1]['y'] + $corners[2]['y'] + $corners[3]['y']) / 4;
            $innerCorners = array_map(function ($c) use ($cx, $cy, $inset) {
                $dx = $c['x'] - $cx;
                $dy = $c['y'] - $cy;
                $dist = sqrt($dx * $dx + $dy * $dy);
                $scale = $dist > 0 ? ($dist - $inset) / $dist : 1;
                return ['x' => (int) round($cx + $dx * $scale), 'y' => (int) round($cy + $dy * $scale)];
            }, $corners);
            $drawParts[] = sprintf(
                "polygon %d,%d %d,%d %d,%d %d,%d",
                $innerCorners[0]['x'], $innerCorners[0]['y'],
                $innerCorners[1]['x'], $innerCorners[1]['y'],
                $innerCorners[2]['x'], $innerCorners[2]['y'],
                $innerCorners[3]['x'], $innerCorners[3]['y']
            );
        }

        $drawString = implode(' ', $drawParts);

        $this->runMagick([
            $magick,
            '-size', "{$w}x{$h}",
            'xc:none',
            '-draw', $drawString,
            $framePath,
        ]);

        return $framePath;
    }

    private function generateTextOverlay(array $bgSize, array $textOptions, string $tempDir): ?string
    {
        $magick = $this->getMagickPath();
        $textPath = $tempDir . '/mockup_text_' . uniqid() . '.png';

        $text = $textOptions['text'] ?? '';
        if (empty($text)) {
            return null;
        }

        $fontSize = $textOptions['fontSize'] ?? 32;
        $fontColor = $textOptions['fontColor'] ?? 'white';
        $position = $textOptions['position'] ?? 'South';
        $offsetY = $textOptions['offsetY'] ?? 30;
        $bgColor = $textOptions['bgColor'] ?? 'rgba(0,0,0,0.5)';

        $w = $bgSize[0];
        $h = $bgSize[1];

        // Create text overlay with background bar
        $this->runMagick([
            $magick,
            '-size', "{$w}x{$h}",
            'xc:none',
            '-gravity', $position,
            '-fill', $bgColor,
            '-draw', "rectangle 0," . ($h - $offsetY - $fontSize - 20) . " {$w},{$h}",
            '-fill', $fontColor,
            '-pointsize', (string) $fontSize,
            '-annotate', "+0+{$offsetY}", $text,
            $textPath,
        ]);

        return $textPath;
    }

    private function getImageSize(string $path): array
    {
        $magick = $this->getMagickPath();

        $result = Process::timeout(30)->run([
            $magick, 'identify', '-format', '%wx%h', $path,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                "Failed to identify image {$path}: " . $result->errorOutput()
            );
        }

        $parts = explode('x', trim($result->output()));

        return [(int) $parts[0], (int) $parts[1]];
    }

    private function runMagick(array $command): void
    {
        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'ImageMagick command failed: ' . $result->errorOutput()
            );
        }
    }
}
