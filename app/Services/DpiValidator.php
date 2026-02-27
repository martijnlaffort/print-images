<?php

namespace App\Services;

class DpiValidator
{
    const SIZES = [
        'A4' => ['width_cm' => 21.0, 'height_cm' => 29.7],
        'A3' => ['width_cm' => 29.7, 'height_cm' => 42.0],
        'A2' => ['width_cm' => 42.0, 'height_cm' => 59.4],
        '50x70' => ['width_cm' => 50.0, 'height_cm' => 70.0],
        '30x40' => ['width_cm' => 30.0, 'height_cm' => 40.0],
    ];

    const PIXELS_AT_300DPI = [
        'A4' => ['width' => 2480, 'height' => 3508],
        'A3' => ['width' => 3508, 'height' => 4960],
        'A2' => ['width' => 4960, 'height' => 7016],
        '50x70' => ['width' => 5906, 'height' => 8268],
        '30x40' => ['width' => 3543, 'height' => 4724],
    ];

    public function validate(string $imagePath, string $size, int $minDpi = 150): array
    {
        [$pixelW, $pixelH] = getimagesize($imagePath);

        $sizeSpec = self::SIZES[$size];

        $dpiW = $pixelW / ($sizeSpec['width_cm'] / 2.54);
        $dpiH = $pixelH / ($sizeSpec['height_cm'] / 2.54);
        $effectiveDpi = min($dpiW, $dpiH);

        return [
            'size' => $size,
            'pixel_width' => $pixelW,
            'pixel_height' => $pixelH,
            'effective_dpi' => round($effectiveDpi),
            'meets_minimum' => $effectiveDpi >= $minDpi,
            'meets_recommended' => $effectiveDpi >= 300,
        ];
    }

    public function validateAll(string $imagePath, int $minDpi = 150): array
    {
        $results = [];
        foreach (array_keys(self::SIZES) as $size) {
            $results[$size] = $this->validate($imagePath, $size, $minDpi);
        }

        return $results;
    }

    public static function sizeNames(): array
    {
        return array_keys(self::SIZES);
    }
}
