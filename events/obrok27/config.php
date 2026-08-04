<?php

// Obrok 2027 — NOTE: the colours and the ghost are the real 2027 identity, but the
// favicons, apple-touch-icon, android-chrome-*.png and safari-pinned-tab.svg under
// www/events/obrok27/ are still the green 2019 shields. An installed PWA therefore
// shows a 2019 icon on a lime splash screen — swap them when the icon set is ready.
return [
    'name' => 'Obrok 2027',
    // fits the home tab; keep it in step with short_name in www/events/obrok27/site.webmanifest
    'shortName' => 'Obrok 27',
    'features' => ['map', 'programs', 'handbook', 'harmonogram', 'news', 'links', 'push'],

    // 2027 visual identity: lime ground, black notched stripes, purple accent
    // (the ghost's outline and the exclamation mark). Same palette as the badges
    // in kissj — public/eventSpecificCss/badgeObrok27.css.
    'colors' => [
        'background' => '#c2ea3a', // lime — the ground for the whole page
        'link' => '#6122eb',       // purple — no browser blue, same as in kissj
        'base' => '#101010',       // black — menu, footer, borders, section headers
        'darker' => '#4a19b5',     // deep purple — menu hover, h2 headings
        'primary' => '#6122eb',    // purple — active menu item, primary buttons
        'text' => '#101010',
        'text-invert' => '#ffffff',
        // active tab in the bottom bar — lime reads 13.7:1 on the dark bar,
        // where the purple accent would only manage 2.7:1
        'nav-active' => '#c2ea3a',
    ],

    // The ghost is generated from the 2027 design photo by docs/generate_obrok27_assets.py
    // in the kissj repo, commit f2cec173 (that commit is not on any branch there — preserve
    // it before regenerating). Lime in the menu so it shows on the black bar; purple elsewhere.
    'assets' => [
        'menuLogo' => 'events/obrok27/ghost-lime.png',
        'mainLogo' => 'events/obrok27/ghost.png',
        'notificationIcon' => 'events/obrok27/ghost.png',
    ],

    'homepage' => [
        'footerLogo' => 'events/obrok27/SKAUT_horizontalni_logo.svg',
        'footerText' => 'Obrok 27 pořádá',
        'footerLinkLabel' => 'Junák - český skaut',
        'footerLinkHref' => 'https://www.skaut.cz/',
    ],

    'map' => [
        // TODO organisers: the Google My Maps URL for the 2027 site map
        'embedUrl' => 'https://www.google.com/maps/d/embed?mid=REPLACE-ME',
    ],

    'handbook' => [
        // TODO organisers: upload the 2027 handbook to www/events/obrok27/
        'file' => 'events/obrok27/obrok27_handbook.pdf',
        'downloadName' => 'Obrok27_handbook.pdf',
    ],

    'programs' => [
        'hiddenNames' => ['Osobní volno'],
    ],

    // The program team fills these in once kissj knows them. Ids must match kissj.
    'sections' => [
        1 => ['id' => 1, 'title' => 'Hlavní program'],
        2 => ['id' => 2, 'title' => 'Doprovodný program'],
    ],
];
