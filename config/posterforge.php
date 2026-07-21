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
        // Bicubic-blend ("AI-blend"): 0 volgens benchmark 20260721 — elke
        // blend > 25 kostte aantoonbaar detail zonder ruisvoordeel.
        'default_denoise' => 0,
        'models' => [
            'realesrgan-x4plus-anime' => 'Real-ESRGAN x4+ (Illustration/Poster)',
            'realesrgan-x4plus' => 'Real-ESRGAN x4+ (Photo-realistic)',
            'realesr-animevideov3' => 'Real-ESRGAN AnimeVideo v3 (licht, snel)',
        ],
    ],

    'export' => [
        'default_quality' => 92,
        'default_format' => 'png',
    ],

    'denoise' => [
        // Benchmark 20260721: élke winnende configuratie had pre-denoise UIT
        // ('light' verloor consequent, 'normal' kostte eerder −81% scherpte).
        // Handmatig inschakelen kan nog steeds; dan is 'light' de default.
        'default_enabled' => false,
        'default_strength' => 'light',
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

    /*
     * Benchmark-harnas: vindt objectief de beste upscale-configuratie voor
     * geschilderde/illustratieve kunst. Alle assen, gewichten en drempels
     * zijn hier instelbaar — niets hardcoded in de services.
     */
    'benchmark' => [
        // Onder storage/app; elke run krijgt een eigen submap (run-id).
        'output_dir' => 'benchmark',

        'target_size' => '50x70',
        'target_dpi' => 300,

        // Elke as is een lijst; de matrix is het cartesisch product.
        // model + pre_denoise bepalen het aantal (dure) AI-passes;
        // blend_bicubic + sharpen zijn goedkope nabewerkingen op een gecachte AI-pass.
        'axes' => [
            'model' => [
                'realesrgan-x4plus',
                'realesrgan-x4plus-anime',
                'realesr-animevideov3',
            ],
            // Wavelet-denoise vóór de AI-pass: off|light|normal|strong.
            'pre_denoise' => ['off', 'light'],
            // % bicubic dat over de AI-output gemengd wordt (de oude "denoise"-knop).
            'blend_bicubic' => [0, 25],
            // Post-USM sterkte 0-100 (0 = uit).
            'sharpen' => [0, 20],
        ],

        // Nulmeting: klassieke bicubic-vergroting van de bron naar hetzelfde doelformaat.
        'baseline_filter' => 'Catrom',

        // Meetblokken worden in de BRON gekozen en naar doelcoördinaten gemapt,
        // zodat elke variant op exact dezelfde beeldinhoud gemeten wordt.
        'blocks' => [
            'size' => 64,        // blokgrootte in bron-pixels
            'detail_count' => 30, // detailrijkste blokken (detailbehoud + textuur)
            'edge_count' => 30,   // randrijkste blokken (gradiëntmeting)
            'noise_count' => 50,  // vlakste blokken (ruismeting, per variant zelf bepaald)
        ],

        // Score-weging (wordt genormaliseerd). Scherpte/detail zwaarder dan ruis:
        // de klantklacht was "wazig"; lichte textuur is voor geschilderde kunst prima.
        'weights' => [
            'detail' => 0.35,  // Laplacian-detail in detailblokken t.o.v. bicubic
            'edges' => 0.30,   // Sobel-gradiëntenergie in randblokken t.o.v. bicubic
            'texture' => 0.20, // lokale sd in detailblokken t.o.v. bicubic (anti-"plastic")
            'noise' => 0.15,   // ruis-sd binnen de doelband
        ],

        // Ruis-sd doelband (0-255-schaal) op de vlakste blokken.
        'noise_target' => ['min' => 1.0, 'max' => 3.0],

        // Ratio-plafonds t.o.v. de bicubic-baseline, zodat oversharpen-artefacten
        // of verzonnen hoogfrequente ruis niet oneindig beloond worden.
        'ratio_caps' => ['detail' => 2.5, 'edges' => 2.5, 'texture' => 2.0],

        'contact_sheet' => [
            'crop_size' => 320, // 100%-uitsnede (doel-resolutie px) per variant
            'columns' => 5,
            'flat_sheet' => true, // ook een sheet van het vlakste gebied (ruisvergelijk)
        ],

        // Ruwe GPU-schatting voor de vooraf-melding van de runduur (GTX 1650, fp16).
        'ai_seconds_per_megapixel' => 55,

        // false = schijf-zuinig: contact-sheet-crops worden direct bewaard en
        // de grote tussenbestanden (varianten, AI-passes, blends) daarna
        // verwijderd. true = alles bewaren om varianten full-size te bekijken
        // (kost al snel meerdere GB's per run).
        'keep_files' => false,

        'magick_timeout' => 600,
    ],

    /*
     * Automatische configuratie-keuze per afbeelding: mini-benchmark op een
     * detailrijke uitsnede + formaat-gating. Kandidatenlijst wordt bijgesteld
     * op basis van benchmark-resultaten.
     */
    'autotune' => [
        'enabled' => true,

        // Uitsnede (bron-px) rond het detailrijkste blok voor de mini-benchmark.
        'crop_size' => 512,

        // Gating: onder deze effectieve DPI wordt een printformaat geweigerd
        // (met melding welk formaat wél haalbaar is).
        'min_dpi' => 200,

        // Meetblokken binnen de mini-benchmark-crop.
        'blocks' => ['detail_count' => 8, 'edge_count' => 8, 'noise_count' => 30],

        // Kandidaat-configuraties voor de mini-benchmark per afbeelding.
        // Shortlist = de top van benchmark-run 20260721_081725 (testset 3 bronnen):
        // pre-denoise 'light' verloor consequent en is daarom geschrapt.
        'candidates' => [
            ['model' => 'realesrgan-x4plus', 'pre_denoise' => 'off', 'blend_bicubic' => 0, 'sharpen' => 20],
            ['model' => 'realesrgan-x4plus', 'pre_denoise' => 'off', 'blend_bicubic' => 25, 'sharpen' => 20],
            ['model' => 'realesrgan-x4plus', 'pre_denoise' => 'off', 'blend_bicubic' => 25, 'sharpen' => 0],
            ['model' => 'realesrgan-x4plus-anime', 'pre_denoise' => 'off', 'blend_bicubic' => 25, 'sharpen' => 20],
            ['model' => 'realesr-animevideov3', 'pre_denoise' => 'off', 'blend_bicubic' => 0, 'sharpen' => 20],
        ],

        // null = gebruik benchmark.weights.
        'weights' => null,
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
