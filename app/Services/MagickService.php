<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class MagickService
{
    public function path(): string
    {
        $configured = config('posterforge.imagemagick_path');
        if ($configured) {
            return $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $matches = glob('C:\\Program Files\\ImageMagick-*\\magick.exe');
            if (! empty($matches)) {
                return $matches[0];
            }

            throw new RuntimeException(
                'ImageMagick not found. Install it from https://imagemagick.org/script/download.php#windows '
                . 'or set IMAGEMAGICK_PATH in your .env file.'
            );
        }

        return 'magick';
    }

    /**
     * Run a magick command. $args excludes the binary itself.
     * Raised resource limits keep tile operations on 50MP posters off the disk cache.
     */
    public function run(array $args, int $timeout = 120): string
    {
        $limits = ['-limit', 'memory', '4GiB', '-limit', 'map', '8GiB'];

        // Subcommands (identify, montage, ...) must precede global options.
        if (in_array($args[0] ?? '', ['identify', 'montage', 'composite', 'compare', 'mogrify'], true)) {
            $cmd = array_merge([$this->path(), $args[0]], $limits, array_slice($args, 1));
        } else {
            $cmd = array_merge([$this->path()], $limits, $args);
        }

        $result = Process::timeout($timeout)->run($cmd);

        if ($result->failed()) {
            throw new RuntimeException(
                'ImageMagick failed (' . implode(' ', array_slice($args, 0, 3)) . '...): '
                . $result->errorOutput()
            );
        }

        return $result->output();
    }
}
