<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class MockupService
{
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

        $posterSize = $this->getImageSize($posterPath);

        $tempDir = sys_get_temp_dir();
        $distortedPath = $tempDir . '/mockup_distorted_' . uniqid() . '.png';

        try {
            // Build perspective control points string:
            // "srcX,srcY,destX,destY  srcX,srcY,destX,destY ..."
            $posterW = $posterSize[0];
            $posterH = $posterSize[1];
            $perspectivePoints = implode('  ', [
                "0,0,{$corners[0]['x']},{$corners[0]['y']}",
                "{$posterW},0,{$corners[1]['x']},{$corners[1]['y']}",
                "{$posterW},{$posterH},{$corners[2]['x']},{$corners[2]['y']}",
                "0,{$posterH},{$corners[3]['x']},{$corners[3]['y']}",
            ]);

            // Distort poster with +distort (auto-viewport) so offset is embedded
            $distortCmd = [
                $magick,
                $posterPath,
                '-alpha', 'set',
                '-virtual-pixel', 'transparent',
                '-background', 'none',
                '+distort', 'Perspective', $perspectivePoints,
            ];

            // Optionally adjust brightness
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

            // Optionally composite shadow
            if (! empty($options['shadowPath']) && file_exists($options['shadowPath'])) {
                $compositeCmd = array_merge($compositeCmd, [
                    $options['shadowPath'], '-compose', 'Multiply', '-composite',
                ]);
            }

            // Optionally composite frame
            if (! empty($options['framePath']) && file_exists($options['framePath'])) {
                $compositeCmd = array_merge($compositeCmd, [
                    $options['framePath'], '-compose', 'Over', '-composite',
                ]);
            }

            // Output as JPEG quality 92
            $compositeCmd = array_merge($compositeCmd, [
                '-quality', '92',
                $outputPath,
            ]);

            $this->runMagick($compositeCmd);

            return $outputPath;
        } finally {
            @unlink($distortedPath);
        }
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
