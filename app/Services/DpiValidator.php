<?php

namespace App\Services;

use App\Models\Setting;

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

    public function allSizes(): array
    {
        $sizes = self::SIZES;

        $custom = Setting::get('print_sizes', []);
        foreach ($custom as $size) {
            $sizes[$size['name']] = [
                'width_cm' => (float) $size['width_cm'],
                'height_cm' => (float) $size['height_cm'],
            ];
        }

        return $sizes;
    }

    public function pixelsAt300Dpi(string $sizeName): ?array
    {
        return $this->pixelsAtDpi($sizeName, 300);
    }

    public function pixelsAtDpi(string $sizeName, int $dpi = 300): ?array
    {
        // For 300 DPI, use pre-calculated values if available
        if ($dpi === 300 && isset(self::PIXELS_AT_300DPI[$sizeName])) {
            return self::PIXELS_AT_300DPI[$sizeName];
        }

        $sizes = $this->allSizes();
        if (! isset($sizes[$sizeName])) {
            return null;
        }

        $spec = $sizes[$sizeName];

        return [
            'width' => (int) round($spec['width_cm'] / 2.54 * $dpi),
            'height' => (int) round($spec['height_cm'] / 2.54 * $dpi),
        ];
    }

    public function calculateEffectiveDpi(string $imagePath, string $sizeName): ?float
    {
        $info = @getimagesize($imagePath);
        if ($info === false) {
            return null;
        }

        $sizes = $this->allSizes();
        if (! isset($sizes[$sizeName])) {
            return null;
        }

        $spec = $sizes[$sizeName];
        $dpiW = $info[0] / ($spec['width_cm'] / 2.54);
        $dpiH = $info[1] / ($spec['height_cm'] / 2.54);

        return round(min($dpiW, $dpiH));
    }

    public function validate(string $imagePath, string $size, int $minDpi = 150): array
    {
        [$pixelW, $pixelH] = getimagesize($imagePath);

        $sizes = $this->allSizes();
        $sizeSpec = $sizes[$size] ?? self::SIZES[$size] ?? null;

        if (! $sizeSpec) {
            return [
                'size' => $size,
                'pixel_width' => $pixelW,
                'pixel_height' => $pixelH,
                'effective_dpi' => 0,
                'meets_minimum' => false,
                'meets_recommended' => false,
            ];
        }

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
        foreach (array_keys($this->allSizes()) as $size) {
            $results[$size] = $this->validate($imagePath, $size, $minDpi);
        }

        return $results;
    }

    public static function sizeNames(): array
    {
        return array_keys(self::SIZES);
    }
}
