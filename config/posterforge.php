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
        // 4xNomos8kSC (Phhofm, CC-BY) getest als beste allrounder voor
        // jouw materiaal: duidelijk scherper dan x4plus op textuur (foto's,
        // impasto-illustraties), gelijk op vlakke illustraties. Attributie:
        // zie 'credits' onderaan deze config.
        'default_model' => '4xNomos8kSC',
        // Bicubic-blend ("AI-blend") = de generatieve-sterkte-demper: het
        // percentage bicubic (interpolatie) dat over de AI-output wordt
        // gemengd. Hoger = minder verzonnen textuur (en zachter). 0 volgens
        // benchmark 20260721 voor illustraties; verhoog dit voor materiaal
        // met fijne regelmatige textuur (haar/vlechtwerk/stof) waar het
        // model plausibel-maar-verkeerd hallucineert.
        'default_denoise' => 0,

        // Proces-timeouts (seconden). Een AI-pass op een grote bron loopt
        // op de GTX 1650 ruim voorbij 5 minuten; de oude harde 300s brak
        // legitieme runs af. magick_timeout dekt de ImageMagick-stappen
        // (bicubic-blend, resize, sharpen) op tot 50MP+ bestanden.
        'ai_timeout' => 1800,
        'magick_timeout' => 600,

        // Vaste tegelgrootte voor realesrgan-ncnn-vulkan (-t). 0 = auto,
        // maar auto kiest de tegel op basis van het vrije VRAM op het
        // startmoment — en de Electron-UI deelt dezelfde 4GB GPU, dus dat
        // is per run anders. Een vaste waarde maakt runs reproduceerbaar
        // en verkleint de kans op corrupte/vlakke tegels door VRAM-druk.
        // Minimaal 32. Verlaag naar 128 als er OOM-artefacten optreden.
        // NB: de tegel-overlap is NIET instelbaar in dit binair (zit
        // hard gecompileerd); alleen de tegelgrootte is te sturen.
        'tile_size' => 256,

        // Bovengrens op de lineaire upscale-factor (doel-px / bron-px).
        // Real-ESRGAN is GENERATIEF: boven deze factor verzint het model
        // de meerderheid van de pixels (plausibel-maar-verkeerde textuur
        // op haar/vlechtwerk/geweven stof). ~2x ≈ bron-DPI 150 voor het
        // formaat (75% van de vlakte-pixels verzonnen). Formaten die méér
        // vragen worden NIET aangeboden en de upscale ervan wordt
        // geweigerd.
        //
        // Op 5.0 voor de illustratie-focus: dit laat 70x100 toe voor
        // ~2K-bronnen (factor ~4.9). Getest en visueel beoordeeld: voor
        // geschilderde illustraties komt 70x100 dan schoon en artefact-vrij
        // uit (printqc PASS, geen tegels/naden), alleen zacht — de
        // kwaliteitsbewaking is dan printqc + een fysieke proefdruk, niet
        // deze grens. LET OP: voor FOTO-realistisch materiaal met fijne
        // echte textuur (haar/huid/water) gaat hoge-factor-verzinnen wel
        // fout; verlaag dit dan richting 2.0. Duurzame fix blijft: bron op
        // hogere native resolutie genereren.
        'max_generative_factor' => 5.0,
        // Alle modellen draaien op realesrgan-ncnn-vulkan (.param/.bin in
        // bin/win/models). De 4x* modellen zijn van Phhofm (CC-BY 4.0,
        // commercieel toegestaan mét naamsvermelding — zie 'credits').
        'models' => [
            '4xNomos8kSC' => '4x Nomos8kSC — foto, scherp (standaard)',
            '4xLSDIRplusC' => '4x LSDIRplusC — foto, maximaal detail',
            '4xLSDIR' => '4x LSDIR — foto, scherp',
            '4xLSDIRCompactC3' => '4x LSDIR Compact — sneller/lichter',
            'realesrgan-x4plus' => 'Real-ESRGAN x4+ — zacht, schoonst op gladde vlakken (water/lucht)',
            'realesrgan-x4plus-anime' => 'Real-ESRGAN x4+ Anime — vlakke illustratie',
            'realesr-animevideov3' => 'Real-ESRGAN AnimeVideo v3 — licht, snel',
        ],
    ],

    'export' => [
        'default_quality' => 92,
        'default_format' => 'png',
    ],

    /*
     * Verplichte naamsvermelding voor de gebruikte upscale-modellen.
     * De 4x*-modellen staan onder CC BY 4.0: commercieel gebruik is
     * toegestaan MITS de maker wordt vermeld. Deze lijst wordt getoond op
     * de Instellingen-pagina (credits) — verwijder de vermelding niet.
     */
    'credits' => [
        'models' => [
            '4xNomos8kSC / 4xLSDIR / 4xLSDIRplusC / 4xLSDIRCompactC3 — © Philip Hofmann (Phhofm), CC BY 4.0 (github.com/Phhofm/models)',
            'Real-ESRGAN (x4plus, AnimeVideo v3) — © Xintao Wang e.a., BSD-3-Clause',
        ],
    ],

    'denoise' => [
        // Benchmark 20260721: élke winnende configuratie had pre-denoise UIT
        // ('light' verloor consequent, 'normal' kostte eerder −81% scherpte).
        // Handmatig inschakelen kan nog steeds; dan is 'light' de default.
        'default_enabled' => false,
        'default_strength' => 'light',

        // Vangnet voor bronnen die al groot genoeg zijn: dan wordt de
        // AI-stap (met zijn impliciete ontruising) overgeslagen en zou de
        // bronruis onbehandeld doorstromen. Ligt de gemeten ruis boven de
        // QC-drempel (qc.noise.acceptable), dan wordt de bron alsnog
        // wavelet-ontruisd vóór de resize. 'auto' schaalt de sterkte mee
        // met de overschrijding (licht / normaal / sterk); een vaste
        // waarde ('light'|'normal'|'strong') kan ook.
        'when_ai_skipped' => [
            'enabled' => true,
            'strength' => 'auto',
        ],
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
     * printqc.py — de laatste poort vóór "klaar voor Gelato". Draait als
     * los Python-script (gebundeld in bin/printqc) in een door de app
     * beheerde venv met pillow + numpy. Exitcode 0 = vrijgeven,
     * 1 = handmatig beoordelen, 2 = blokkeren (bestand wordt hernoemd
     * naar *_GEBLOKKEERD.png zodat het nooit per ongeluk naar Gelato gaat).
     * Kan de poort zelf niet draaien (geen Python, venv stuk), dan wordt
     * de export als 'handmatig beoordelen' gemarkeerd — nooit stilzwijgend
     * vrijgegeven.
     */
    'printqc' => [
        // Basis-Python om de venv mee te bootstrappen; leeg = zelf zoeken
        // (python / py -3 op PATH).
        'python' => env('PRINTQC_PYTHON'),
        'timeout' => 900,
        // Formaten die printqc.py kent; alleen daarvoor wordt --formaat
        // meegegeven. Andere formaten krijgen van het script zelf een
        // REVIEW ("resolutiecontrole overgeslagen") = handmatig beoordelen.
        'formats' => ['30x40', '40x50', '50x70', '70x100'],
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
        // Bake-off 2026-08 (foto + illustraties): 4xNomos8kSC en
        // 4xLSDIRplusC (Phhofm, CC-BY) winnen op textuur; x4plus blijft
        // kandidaat omdat het op grote gladde vlakken (water/lucht) de
        // minste korrel geeft. Autotune kiest per beeld de beste.
        'candidates' => [
            ['model' => '4xNomos8kSC', 'pre_denoise' => 'off', 'blend_bicubic' => 0, 'sharpen' => 20],
            ['model' => '4xLSDIRplusC', 'pre_denoise' => 'off', 'blend_bicubic' => 0, 'sharpen' => 20],
            ['model' => 'realesrgan-x4plus', 'pre_denoise' => 'off', 'blend_bicubic' => 0, 'sharpen' => 20],
        ],

        // null = gebruik benchmark.weights.
        'weights' => null,
    ],

    'qc' => [
        'block_size' => 64,
        'flattest_count' => 50,
        // Mean standard deviation (0-255 scale) of the flattest blocks.
        // Ruis-sd (gemiddelde van de vlakste blokken, gemeten op
        // GRIJSWAARDEN — deze meting heeft de kanaalspreidings-bug van de
        // oude numpy-scripts niet). De banden gelden alleen als de meting
        // betrouwbaar is: het állervlakste blok moet onder 'reliable_max'
        // zitten; anders meet de methode textuur en is de status
        // 'unreliable'.
        //
        // LET OP — ONGEIJKT: deze getallen stammen uit de kalibratie die
        // ongeldig is verklaard (geijkt tegen de foute kanaal-brede
        // meting). Ruis geeft daarom NOOIT meer een harde FAIL, alleen
        // een waarschuwing/handmatige beoordeling, totdat de banden
        // opnieuw geijkt zijn tegen een fysieke print. Geen nieuwe
        // getallen verzinnen — ijking doet de gebruiker zelf.
        'noise' => [
            'pass' => 3.0,
            'warn' => 4.5,
            'reliable_max' => 5.0,
        ],
        // Fine grain: mean Laplacian std-dev (0-255) inside the flattest blocks.
        'grain' => [
            'clean' => 1.0,
            'acceptable' => 3.0,
        ],
        // Kleur-check is informatief (warmte is beeldafhankelijk, nooit een
        // fail). Alleen zeer hoge gemiddelde verzadiging (HSB, 0-100%)
        // geeft een WARN: die oogt op print snel minder levendig dan op
        // scherm.
        'color' => [
            'saturation_warn' => 60,
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
