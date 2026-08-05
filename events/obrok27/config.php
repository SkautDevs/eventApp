<?php

// Obrok 2027 — NOTE: the colours and the ghost are the real 2027 identity, but the
// favicons, apple-touch-icon, android-chrome-*.png and safari-pinned-tab.svg under
// www/events/obrok27/ are still the green 2019 shields. An installed PWA therefore
// shows a 2019 icon on a lime splash screen — swap them when the icon set is ready.
return [
    'name' => 'Obrok 2027',
    // fits the home tab; keep it in step with short_name in www/events/obrok27/site.webmanifest
    'shortName' => 'Obrok 27',
    'features' => ['map', 'programs', 'handbook', 'news', 'links', 'push'],

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

    // Look and feel, on top of the palette. Every key here is optional: www/style.css
    // carries the untouched Obrok 19 rendering as the var() fallback for each one, so
    // an event that declares no theme at all is unaffected. Values are raw CSS and
    // point back at the palette above rather than naming colours of their own.
    // Look and feel that is not colour: type and corner rounding. Colour lives in
    // 'roles' below, because it has to exist twice — once light, once dark.
    'theme' => [
        // Montserrat replaces themix for body copy and Skaut's bold for the app bar
        // title — 400 and 700, the only two weights the app asks for.
        'font' => "'Montserrat', sans-serif",
        'font-display' => "'Montserrat', sans-serif",
        'font-display-weight' => '700',
        'font-url' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap',

        // 2027 is blocky: the notched stripes of the identity have no round corners,
        // so neither does anything in the app.
        'radius' => '0',
        'radius-pill' => '0',
        'radius-sheet' => '0',
        'radius-card' => '0',
    ],

    // ------------------------------------------------------------------------
    // SEMANTIC COLOUR ROLES, light and dark.
    //
    // www/style.css names none of the palette keys above; it reads these roles.
    // A colour holds exactly one role — lime is `state` and therefore not also a
    // ground, a surface or a piece of chrome, which is what stopped every accent
    // reading as an accent when the page itself was full-strength lime.
    //
    // The budget is 60:30:10 — a tinted neutral `ground` (plus `surface`, its one
    // block tier), near-black `structure` for the bars, the buttons and the
    // timeline's chrome, and a 10 split between purple `action` and lime `state`.
    //
    // THE TWO SETS ARE A TWO-PLACE EDIT. Changing a colour here means changing it
    // in both, deliberately: accents do not survive mechanical inversion, and the
    // dark values below are hand-picked, not derived. Removing the dark set makes
    // the event light-only and removes the toggle from its app bar.
    // ------------------------------------------------------------------------
    'roles' => [
        'light' => [
            // 60 — a near-white carrying only a trace of the lime, not a lime wash
            'ground' => '#f6f7f3',
            'on-ground' => '#14160f',
            'surface' => '#ffffff',
            'on-surface' => '#14160f',

            // 30 — structure. Light mode separates it from the ground by tone
            // alone, so it declares no edge width and stays outline-free.
            'structure' => '#14160f',
            'on-structure' => '#f6f7f3',
            'edge-width' => '0px',
            'edge' => '#14160f',

            // 10a — action
            'action' => '#6122eb',
            'on-action' => '#ffffff',
            'action-link' => '#6122eb',

            // 10b — state
            'state' => '#c2ea3a',
            'on-state' => '#14160f',

            'heading' => '#4a19b5',
            // ink with more weight than body copy: h3 and the pager's icon controls
            'emphasis' => '#14160f',
            'scrim' => '#14160f',
            // the selected view tab, which is state like the selected screen is
            'view-state' => '#c2ea3a',
            'on-view-state' => '#14160f',
            'field' => '#ffffff',
            'on-field' => '#14160f',
            'sheet' => '#ffffff',
            'on-sheet' => '#14160f',
            'sheet-action' => '#6122eb',
            'on-sheet-action' => '#ffffff',

            // the timeline, in the same vocabulary: a light neutral grid with the
            // stage column and the cards on it as structure
            'grid' => '#d6d9cf',
            'grid-structure' => '#14160f',
            'on-grid-structure' => '#f6f7f3',
            'grid-edge' => '#14160f',
            // Structure that carries the identity, and the only place in the app
            // that wears it: the hour ruler. The purple is the identity colour, so
            // it is the same value as `action` — that is the role's whole point,
            // and it is why there is exactly one of them. 6.65:1 under its labels.
            'signature' => '#6122eb',
            'on-signature' => '#f6f7f3',
            // The stage column is structure, not state — a full-lime column was an
            // accent painted on the biggest block of the screen. It takes a CAST of
            // the lime instead (12% into the structure black), so the grid reads as
            // 2027 without spending accent on the area. The names go from 16.95:1 to
            // 12.92:1, which is the price of any visible cast on a near-black.
            'stage' => '#292f14',
            'on-stage' => '#f6f7f3',
            // light mode can leave the card's own boundary a ghost of the grid edge,
            // because there the card's fill already separates from the grid
            'hairline' => 'color-mix(in srgb, var(--role-grid-edge) 45%, transparent)',
            // a registered programme keeps the neutral card and takes a thick edge
            // in the state colour — the only lime on the screen besides the tab bar
            'registered-bg' => '#14160f',
            'registered-line' => '#c2ea3a',
            'registered-width' => '3px',
        ],

        'dark' => [
            // 60 — a near-black with the same trace of lime. Never pure black, and
            // the type on it never pure white: that pair halates at small sizes.
            'ground' => '#12140e',
            'on-ground' => '#e6e8de',
            'surface' => '#1b1e15',
            'on-surface' => '#e6e8de',

            // 30 — structure stays near-black and is told apart from the ground by
            // a hairline in the accent rather than by a lifted tone. That is why
            // outlines exist here and nowhere in light mode.
            'structure' => '#0c0e08',
            'on-structure' => '#e6e8de',
            'edge-width' => '1px',
            // 3.2:1 against the ground — a hairline still has to be findable
            'edge' => '#6b57b6',

            // 10a — the light purple will not do here: #6122eb is about 2.5:1 on
            // this ground. Lifted and slightly desaturated for the same role.
            'action' => '#b39cff',
            'on-action' => '#12100c',
            'action-link' => '#b39cff',

            // 10b — the lime survives inversion; it is only ever ink or a hairline,
            // never a filled area, so it does not glare
            'state' => '#c2ea3a',
            'on-state' => '#12140e',

            'heading' => '#c3b0ff',
            // near-black as ink would vanish on this ground, so emphasis inverts
            'emphasis' => '#e6e8de',
            'scrim' => '#05060a',
            'view-state' => '#c2ea3a',
            'on-view-state' => '#12140e',
            'field' => '#1b1e15',
            'on-field' => '#e6e8de',
            'sheet' => '#1b1e15',
            'on-sheet' => '#e6e8de',
            'sheet-action' => '#b39cff',
            'on-sheet-action' => '#12100c',

            // Elevation reads the other way up at night: the grid's chrome and its
            // cards are LIGHTER than the grid ground, not darker.
            'grid' => '#191c14',
            // The card has to be legible as a card BEFORE its hairline is counted.
            // At #343a2d it was 1.47:1 against the grid and the hairline was doing
            // all the work, which reads as murk; this is 3.24:1 on fill alone, with
            // the labels at 4.79:1 on it. The two pull against each other — every
            // step lighter buys grid separation and spends label contrast — so this
            // sits where both clear their threshold with room, and the card stays
            // NEUTRAL, which is what keeps programme names readable.
            'grid-structure' => '#686d60',
            'on-grid-structure' => '#f2f4ea',
            'grid-edge' => '#787e6d',
            // the ruler's purple, lifted for the night: #6122eb is 2.5:1 on this
            // ground. This is 4.68:1 against the grid and carries dark ink at 5.03:1.
            'signature' => '#8f6ff0',
            'on-signature' => '#12140e',
            // the lime cast, dark: 16% into a tone of the grid family. 7.08:1 under
            // the stage names, and 2.19:1 against the grid where round 8 was 1.47:1.
            'stage' => '#4b562f',
            'on-stage' => '#f2f4ea',
            // lifted with the card: at #787e6d the hairline is 1.2:1 against the new
            // fill, i.e. gone. This one is 2.3:1 on it and 7.5:1 on the grid.
            'hairline' => '#a7ad97',
            'registered-bg' => '#686d60',
            'registered-line' => '#c2ea3a',
            'registered-width' => '3px',
        ],
    ],

    // The ghost is generated from the 2027 design photo by docs/generate_obrok27_assets.py
    // in the kissj repo, commit f2cec173 (that commit is not on any branch there — preserve
    // it before regenerating). Lime in the menu so it shows on the black bar; purple elsewhere.
    'assets' => [
        'menuLogo' => 'events/obrok27/ghost-lime.png',
        'mainLogo' => 'events/obrok27/ghost.png',
        // the purple ghost measures about 2.5:1 on the dark ground; the lime one,
        // drawn for the black menu bar, is what carries the homepage at night
        'mainLogoDark' => 'events/obrok27/ghost-lime.png',
        'notificationIcon' => 'events/obrok27/ghost.png',
    ],

    'homepage' => [
        'footerLogo' => 'events/obrok27/SKAUT_horizontalni_logo.svg',
        'footerText' => 'Obrok 27 pořádá',
        'footerLinkLabel' => 'Junák - český skaut',
        'footerLinkHref' => 'https://www.skaut.cz/',
    ],

    'map' => [
        // TODO organisers: replace with the Google My Maps URL for the 2027 site.
        // This is the 2019 Konopiště map, standing in so the tab shows a working
        // map instead of a placeholder — the pins are NOT the 2027 site.
        'embedUrl' => 'https://www.google.com/maps/d/u/1/embed?mid=1-c6E-PUffBQyiivt-tSURDBIwABH9X-I',
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
