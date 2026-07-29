<?php

// Konfigurace akce Obrok 2019 (referenční akce — vzor pro nové akce)
return [
    'name' => 'Obrok 2019',
    'features' => [
        'news',
        'map',
        'links',
        'handbook',
        // TODO: re-enable as modules land (Tasks 5-9)
        // 'programs',
        // 'harmonogram',
    ],

    // Paleta — hodnoty z www/style.css :root
    'colors' => [
        'base' => '#2a9272',
        'darker' => '#1e6650',
        'primary' => '#96201f',
        'text' => '#444343',
        'text-invert' => '#ffffff',
    ],

    'assets' => [
        'menuLogo' => 'events/obrok19/Obrok19_bily_stit_160.png',
        'mainLogo' => 'events/obrok19/Obrok19_tmave_logo_800.png',
        'notificationIcon' => 'events/obrok19/Obrok19_minilogo.png',
    ],

    'homepage' => [
        'footerLogo' => 'events/obrok19/SKAUT_horizontalni_logo.svg',
        'footerText' => 'Obrok 19 pořádá',
        'footerLinkLabel' => 'Junák - český skaut',
        'footerLinkHref' => 'https://www.skaut.cz/',
    ],

    'map' => [
        'embedUrl' => 'https://www.google.com/maps/d/u/1/embed?mid=1-c6E-PUffBQyiivt-tSURDBIwABH9X-I',
    ],

    'handbook' => [
        'file' => 'events/obrok19/obrok19_handbook.pdf',
        'downloadName' => 'Obrok19_handbook.pdf',
    ],

    'programs' => [
        'hiddenNames' => ['Osobní volno'],
    ],

    // Sekce programu — dříve HttpService::getSectionsLocal().
    // 'image'/'attachment' nahrazují natvrdo zadrátované mapy v programs.twig.
    'sections' => [
        10 => ['id' => 10, 'title' => 'Putování'],
        12 => ['id' => 12, 'title' => 'Večerní programy'],
        13 => ['id' => 13, 'title' => 'Doprovodné programy'],
        1 => ['id' => 1, 'title' => 'Služba'],
        2 => ['id' => 2, 'title' => 'Vzlet', 'image' => 'events/obrok19/map-vzlet.png'],
        11 => ['id' => 11, 'title' => 'Pamětníci'],
        14 => ['id' => 14, 'title' => 'M(a)y Day', 'image' => 'events/obrok19/map-mayday.png'],
        15 => ['id' => 15, 'title' => 'Velká hra', 'image' => 'events/obrok19/map-velkahra.png'],
        16 => ['id' => 16, 'title' => 'EXPO'],
        3 => ['id' => 3, 'title' => 'Vapro', 'subTitle' => '1. blok'],
        4 => ['id' => 4, 'title' => 'Vapro', 'subTitle' => '2. blok'],
        17 => [
            'id' => 17,
            'title' => 'Netradiční sporty',
            'image' => 'events/obrok19/map-netradicni-sporty.png',
            'attachment' => ['href' => 'events/obrok19/netradicni-sporty.pdf', 'label' => 'Pravidla a více informací zde'],
        ],
        18 => ['id' => 18, 'title' => 'Mše'],
    ],
];
