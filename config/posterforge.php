<?php

return [
    'imagemagick_path' => env('IMAGEMAGICK_PATH'),

    'naming' => [
        'upscaled' => '{title}_upscaled.png',
        'size_variant' => '{title}_{size}.png',
        'mockup' => '{title}_mockup_{template}.jpg',
    ],

    'upscale' => [
        'default_target_size' => '70x100',
        'default_scale' => 4,
        'default_model' => 'realesrgan-x4plus',
        'default_denoise' => 50,
        'models' => [
            'realesrgan-x4plus-anime' => 'Real-ESRGAN x4+ (Illustration/Poster)',
            'realesrgan-x4plus' => 'Real-ESRGAN x4+ (Photo-realistic)',
        ],
    ],

    'export' => [
        'default_quality' => 92,
        'default_format' => 'png',
    ],

    'denoise' => [
        'default_enabled' => true,
        'default_strength' => 'normal',
        // Wavelet-denoise threshold (percent of quantum range) per strength.
        'strengths' => [
            'light' => 1.0,
            'normal' => 3.0,
            'strong' => 6.0,
        ],
    ],

    'icc' => [
        // Falls back to the bundled sRGB IEC61966-2.1 profile when unset.
        // Point this at a Gelato-specific profile later if they advise one.
        'profile_path' => env('ICC_PROFILE_PATH'),
        'embed' => true,
    ],

    'qc' => [
        'block_size' => 64,
        'flattest_count' => 50,
        // Mean standard deviation (0-255 scale) of the flattest blocks.
        'noise' => [
            'clean' => 1.0,
            'acceptable' => 3.0,
        ],
        // Fine grain: mean Laplacian std-dev (0-255) inside the flattest blocks.
        'grain' => [
            'clean' => 1.0,
            'acceptable' => 3.0,
        ],
        // Warn when full-image Laplacian sharpness drops more than this after denoise.
        'detail_loss_warn_percent' => 30,
        'dpi' => [
            'ideal' => 300,
            'acceptable' => 200,
        ],
        // Print sizes evaluated in every QC report.
        'sizes' => ['21x30', '30x40', '40x50', '50x70', '70x100'],
        // Edge length (px) of the before/after comparison crops (100% zoom).
        'crop_size' => 400,
        'magick_timeout' => 300,
    ],
];
