<?php

// Obrok 2027 — NOTE: the colours and the ghost are the real 2027 identity, but the
// favicons, apple-touch-icon, android-chrome-*.png and safari-pinned-tab.svg under
// www/events/obrok27/ are still the green 2019 shields. An installed PWA therefore
// shows a 2019 icon on a lime splash screen — swap them when the icon set is ready.
return [
    'name' => 'Obrok 2027',
    // fits the home tab; keep it in step with short_name in www/events/obrok27/site.webmanifest
    'shortName' => 'Obrok 27',
    'listed' => true,
    'dates' => ['start' => '2027-06-02', 'end' => '2027-06-05'],
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
        // title — 400 and 700, the only two weights the app asks for. Served from
        // www/fonts/montserrat/ by the @font-face rules in www/style.css.
        'font' => "'Montserrat', sans-serif",
        'font-display' => "'Montserrat', sans-serif",
        'font-display-weight' => '700',

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
    // block tier), `structure` for the bars, the buttons and the timeline's chrome,
    // and a 10 split between purple `action` and lime `state`.
    //
    // LIGHT IS LIGHT AND DARK IS DARK. `structure` is white in the light set and
    // near-black in the dark one — it is the ground of every bar, sheet and card,
    // and a near-black bar in light mode is the thing this round removed. The lime
    // is 1.25:1 on white, so in light mode it can only ever be a filled chip under
    // dark ink, never ink and never a hairline; the purple is 6.65:1 and is free to
    // be type. Both modes separate a bar from the ground with a tone step AND a
    // hairline, because a white card scrolling under a white bar needs a line.
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

            // 30 — structure: the app bar, the tab bar, the sheet, the timeline's
            // cards. White, a tone step above the ground and a hairline away from
            // it, because content scrolls under both bars.
            'structure' => '#ffffff',
            'on-structure' => '#14160f',
            'edge-width' => '1px',
            // a NEUTRAL hairline in light mode: 3.72:1 on the white it bounds and
            // 3.46:1 on the ground it separates that white from
            'edge' => '#82867a',

            // 10a — action. 7.15:1 under white, and 6.65:1 as ink on the ground.
            'action' => '#6122eb',
            'on-action' => '#ffffff',
            'action-link' => '#6122eb',
            // the quiet form of it — the purple washed almost all the way into the
            // ground, carrying the purple itself at full strength as ink (5.89:1).
            // The secondary button, the link CTA, the notice and the active day.
            'tonal' => '#ece6fd',
            'on-tonal' => '#6122eb',

            // 10b — state. The lime is 1.25:1 on white and can never be ink here;
            // as a fill under near-black ink it is 13.14:1, which is what the app
            // bar and the active tab wear in both modes.
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

            // the timeline, in the same vocabulary: a faint neutral grid carrying
            // white cards, with the stage column a plain grey band of chrome
            'grid' => '#dcded5',
            'grid-structure' => '#ffffff',
            'on-grid-structure' => '#14160f',
            'grid-edge' => '#9ba090',
            // Structure that carries the identity, and the only place in the app
            // that wears it: the hour ruler. A QUIET cast of the purple rather than
            // the full-strength slab it was — at full strength it is the loudest
            // thing on a light screen, and it is chrome. 7.13:1 under its labels.
            'signature' => '#c7b3f5',
            'on-signature' => '#351086',
            // The stage column is a plain neutral. The lime cast is withdrawn: the
            // identity is carried by the ruler and the registered cards, and a hue
            // on the largest block of the screen is how accents stop reading.
            'stage' => '#bfc2b7',
            'on-stage' => '#14160f',
            // 4.44:1 on the white card and 3.27:1 on the grid, so the card's
            // boundary holds against both sides of itself
            'hairline' => '#76796d',
            // registered: the lime as a fill under the card's own dark ink, which
            // is the one shape it survives on a light grid
            'registered-bg' => '#c2ea3a',
            'registered-line' => '#76796d',
            'registered-width' => '1px',
        ],

        'dark' => [
            // 60 — a near-black with the same trace of lime. Never pure black, and
            // the type on it never pure white: that pair halates at small sizes.
            'ground' => '#0f1109',
            'on-ground' => '#e6e8de',
            'surface' => '#252a1c',
            'on-surface' => '#e6e8de',

            // 30 — structure is one step OFF the ground rather than under it (at
            // night, closer to the reader is lighter), and the accent-tinted
            // hairline does the rest: 4.41:1 on the ground, 3.59:1 on the bar.
            'structure' => '#21261a',
            'on-structure' => '#e6e8de',
            'edge-width' => '1px',
            'edge' => '#7f6ad0',

            // 10a — the light purple will not do here: #6122eb is about 2.5:1 on
            // this ground. Lifted and slightly desaturated for the same role.
            'action' => '#b39cff',
            'on-action' => '#12100c',
            'action-link' => '#b39cff',
            // the same wash the other way up: the purple mixed down into the ground,
            // carrying the lifted purple as ink at 6.40:1
            'tonal' => '#2a2249',
            'on-tonal' => '#b39cff',

            // 10b — the lime is a small fill here too, under dark ink, so it never
            // becomes a glaring lit area at night
            'state' => '#c2ea3a',
            'on-state' => '#12140e',

            'heading' => '#c3b0ff',
            // near-black as ink would vanish on this ground, so emphasis inverts
            'emphasis' => '#e6e8de',
            'scrim' => '#05060a',
            'view-state' => '#c2ea3a',
            'on-view-state' => '#12140e',
            'field' => '#252a1c',
            'on-field' => '#e6e8de',
            'sheet' => '#252a1c',
            'on-sheet' => '#e6e8de',
            'sheet-action' => '#b39cff',
            'on-sheet-action' => '#12100c',

            // Elevation reads the other way up at night: the grid's chrome and its
            // cards are LIGHTER than the grid ground, not darker.
            'grid' => '#15180f',
            // The card is a card by a tone step AND its hairline together. Round 9
            // asked the fill alone for 3:1, which on a near-black grid means a
            // mid-grey block — a light element in a dark screen, which is what the
            // round-10 complaint is about. 1.98:1 on fill, 3.92:1 on the hairline,
            // and the labels sit at 8.17:1 on it.
            'grid-structure' => '#454b36',
            'on-grid-structure' => '#f2f4ea',
            'grid-edge' => '#5f6553',
            // the ruler at night: a deep purple band under light type, not the lit
            // panel a bright purple makes. 2.15:1 against the grid, 7.05:1 under
            // its labels.
            'signature' => '#523f96',
            'on-signature' => '#ede9ff',
            // neutral here too — the lime cast is withdrawn in both modes
            'stage' => '#2f3427',
            'on-stage' => '#eef0e6',
            // 3.92:1 against the card it bounds and 7.75:1 against the grid
            'hairline' => '#a7ad97',
            // registered: on a dark grid the lime is a thick edge on the neutral
            // card (6.54:1 on it), because a lime fill at night is a lamp
            'registered-bg' => '#454b36',
            'registered-line' => '#c2ea3a',
            'registered-width' => '3px',
        ],
    ],

    // The ghost is generated from the 2027 design photo by docs/generate_obrok27_assets.py
    // in the kissj repo, commit f2cec173 (that commit is not on any branch there — preserve
    // it before regenerating). Lime in the menu so it shows on the black bar; purple elsewhere.
    'assets' => [
        // The bars follow the mode now, so the drawing on them has to as well: the
        // purple ghost on the white bars of light mode (6.65:1) and the lime one on
        // the near-black bars at night, where the purple is about 2.5:1.
        //
        // The -160 files are the same two drawings at 123x160 instead of 620x804.
        // The bars draw them at 26px and the tabs at 20px, so the full-size pair was
        // 136 KB of PNG for a mark rendered 20 pixels wide — and both were fetched on
        // every load, because the mode swap is a CSS one and each mode's twin is in
        // the document either way. 160px on the long edge still has headroom on a 3x
        // display. The 620px originals stay for the homepage below, where the drawing
        // genuinely renders at 256x332.
        'menuLogo' => 'events/obrok27/ghost-160.png',
        'menuLogoDark' => 'events/obrok27/ghost-lime-160.png',
        // and on the active tab's lime fill, in either mode, the purple one again:
        // the dark-mode drawing is lime, which is the colour the tab is filled with
        'menuLogoOnState' => 'events/obrok27/ghost-160.png',
        'mainLogo' => 'events/obrok27/ghost.png',
        // the purple ghost measures about 2.5:1 on the dark ground; the lime one,
        // drawn for the black menu bar, is what carries the homepage at night
        'mainLogoDark' => 'events/obrok27/ghost-lime.png',
        'notificationIcon' => 'events/obrok27/ghost.png',
        // Safari's pinned-tab mask; an event without the drawing leaves the key out
        'pinnedTab' => 'events/obrok27/safari-pinned-tab.svg',
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
];
