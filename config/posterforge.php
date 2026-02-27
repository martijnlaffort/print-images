<?php

return [
    'naming' => [
        'upscaled' => '{title}_upscaled.png',
        'size_variant' => '{title}_{size}.png',
        'mockup' => '{title}_mockup_{template}.jpg',
    ],

    'upscale' => [
        'default_scale' => 4,
        'default_model' => 'realesrgan-x4plus',
        'models' => [
            'realesrgan-x4plus' => 'Real-ESRGAN x4+ (General)',
            'realesrgan-x4plus-anime' => 'Real-ESRGAN x4+ (Anime/Illustration)',
        ],
    ],

    'export' => [
        'default_quality' => 92,
        'default_format' => 'png',
    ],
];
