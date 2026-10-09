<?php

// Obrok 2019 event config (the reference event — template for new ones)
return [
    'name' => 'Obrok 2019',
    // fits the home tab; keep it in step with short_name in www/events/obrok19/site.webmanifest
    'shortName' => 'Obrok 19',
    'features' => [
        'map',
        'programs',
        'handbook',
        'news',
        'links',
        'push',
    ],

    // Palette — values taken from the old www/style.css :root
    'colors' => [
        'background' => '#ffffff',
        // The browser default blue, so unvisited links look exactly as they did in 2019.
        // Visited links used to be #551a8b and are now this same blue — deliberate, so a
        // link doesn't change colour under the reader mid-event.
        'link' => '#0000ee',
        'base' => '#2a9272',
        'darker' => '#1e6650',
        'primary' => '#96201f',
        'text' => '#444343',
        'text-invert' => '#ffffff',
    ],

    // ------------------------------------------------------------------------
    // SEMANTIC COLOUR ROLES — light only.
    //
    // Obrok 19 was the frozen control for five rounds and is not one any more:
    // its bars are white and its green is an accent, like every other event's.
    // The values below are its own palette above mapped into the role layer, not
    // new colours — the one thing that is genuinely new is a set of neutrals for
    // the hairlines and the timeline's grid, which no 2019 palette key holds.
    //
    // THE EVENT STAYS LIGHT-ONLY. There is no `dark` set, so the app bar shows no
    // mode toggle and the stylesheet's dark-mode plumbing never fires. Adding one
    // means hand-authoring it: the accents here do not survive inversion either.
    // ------------------------------------------------------------------------
    'roles' => [
        'light' => [
            // 60 — a faint neutral off-white, a few steps in from pure white
            'ground' => '#f7f7f5',
            'on-ground' => '#444343',
            'surface' => '#ffffff',
            'on-surface' => '#444343',

            // 30 — the bars, the sheet and the timeline's cards. White, told apart
            // from the ground by a tone step and the neutral hairline below.
            'structure' => '#ffffff',
            'on-structure' => '#444343',
            'edge-width' => '1px',
            'edge' => '#84847f',

            // 10a — action. The 2019 browser blue for links is deliberate and stays.
            'action' => '#96201f',
            'on-action' => '#ffffff',
            'action-link' => '#0000ee',
            // the quiet form of it: the primary washed into the ground, ink at full
            // strength. The secondary button and the link CTA wear this.
            'tonal' => '#f4e6e5',
            'on-tonal' => '#96201f',

            // 10b — state. The green is 3.49:1 as ink on white and cannot be type;
            // as a filled chip under near-black ink it is 4.83:1, which is the only
            // shape it survives in and the one the active tab uses.
            'state' => '#2a9272',
            'on-state' => '#101410',

            'heading' => '#1e6650',
            'emphasis' => '#1e6650',
            'scrim' => '#12140f',
            'view-state' => '#2a9272',
            'on-view-state' => '#101410',
            'field' => '#ffffff',
            'on-field' => '#444343',
            // the sheet is a white panel with dark type now, which is what retired
            // the old 'sheet-bg' palette key and its dark green ground
            'sheet' => '#ffffff',
            'on-sheet' => '#444343',
            'sheet-action' => '#96201f',
            'on-sheet-action' => '#ffffff',

            // the timeline: a faint neutral grid, white cards, a grey stage column
            'grid' => '#e0e1dd',
            'grid-structure' => '#ffffff',
            // near-black rather than the body ink, because this one pair has to
            // carry both the white card (17:1) and the green registered fill (4.8:1)
            'on-grid-structure' => '#101410',
            'grid-edge' => '#9c9c96',
            // structure that carries the identity, and its only use: the hour ruler.
            // A quiet cast of the event's green, not the full-strength band.
            'signature' => '#bfded1',
            'on-signature' => '#14503e',
            // the stage column is a plain neutral — no cast of the identity
            'stage' => '#c3c4bf',
            'on-stage' => '#262625',
            // 4.57:1 on the card and 3.48:1 on the grid, so the boundary holds on
            // both sides of itself
            'hairline' => '#76766f',
            // registered: the green as a fill under the card's own near-black ink
            'registered-bg' => '#2a9272',
            'registered-line' => '#76766f',
            'registered-width' => '1px',
        ],
    ],

    'assets' => [
        // the dark logo, drawn small: the white shield this used to be is invisible
        // on the white app bar the role set above gives it
        'menuLogo' => 'events/obrok19/Obrok19_minilogo.png',
        // the white shield, which is what the active tab's green chip needs on it —
        // the dark logo above is the same drawing in the chip's own colour
        'menuLogoOnState' => 'events/obrok19/Obrok19_bily_stit_160.png',
        'mainLogo' => 'events/obrok19/Obrok19_tmave_logo_800.png',
        'notificationIcon' => 'events/obrok19/Obrok19_minilogo.png',
        // Safari's pinned-tab mask; an event without the drawing leaves the key out and the
        // layout links nothing (rather than a 404 on every page)
        'pinnedTab' => 'events/obrok19/safari-pinned-tab.svg',
    ],

    'homepage' => [
        'footerLogo' => 'events/obrok19/SKAUT_horizontalni_logo.svg',
        'footerText' => 'Obrok 19 pořádá',
        'footerLinkLabel' => 'Junák - český skaut',
        'footerLinkHref' => 'https://www.skaut.cz/',
    ],

    'map' => [
        'embedUrl' => 'https://www.google.com/maps/d/u/1/embed?mid=1-c6E-PUffBQyiivt-tSURDBIwABH9X-I',
        // A sample plan (the CEJ 2022 site map), not the 2019 site: it gives the reference
        // event the Mapa screen's second view, and the browser tests (MapViewsTest,
        // OfflineTest) a plan.
        'image' => 'events/obrok19/plan-example.png',
        'imageAlt' => 'Ukázkový plán areálu',
    ],

    'handbook' => [
        'file' => 'events/obrok19/obrok19_handbook.pdf',
        'downloadName' => 'Obrok19_handbook.pdf',
    ],
];
