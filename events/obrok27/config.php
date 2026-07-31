<?php

// Obrok 2027 — POZOR: barvy a loga jsou zatím převzaté z 2019,
// vyměnit až bude vizuální identita 2027 (viz úkol v README akce).
return [
    'name' => 'Obrok 2027',
    'features' => ['map', 'programs', 'harmonogram', 'news', 'links', 'push'],

    'colors' => [
        'base' => '#2a9272',
        'darker' => '#1e6650',
        'primary' => '#96201f',
        'text' => '#444343',
        'text-invert' => '#ffffff',
    ],

    'assets' => [
        'menuLogo' => 'events/obrok27/logo-menu.png',
        'mainLogo' => 'events/obrok27/logo-main.png',
        'notificationIcon' => 'events/obrok27/logo-mini.png',
    ],

    'homepage' => [
        'footerLogo' => 'events/obrok27/SKAUT_horizontalni_logo.svg',
        'footerText' => 'Obrok 27 pořádá',
        'footerLinkLabel' => 'Junák - český skaut',
        'footerLinkHref' => 'https://www.skaut.cz/',
    ],

    'map' => [
        // TODO organizátoři: URL Google My Maps mapy areálu 2027
        'embedUrl' => 'https://www.google.com/maps/d/embed?mid=REPLACE-ME',
    ],

    'programs' => [
        'hiddenNames' => ['Osobní volno'],
    ],

    // Sekce doplní programový tým, až je kissj bude znát. Id musí sedět na kissj.
    'sections' => [
        1 => ['id' => 1, 'title' => 'Hlavní program'],
        2 => ['id' => 2, 'title' => 'Doprovodný program'],
    ],
];
