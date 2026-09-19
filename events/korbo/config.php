<?php

// Korbo 2026 — a DEV event, not a shipped one. It exists to click through the realistic
// Korbo camp data (16–20 Sep 2026) on the real Program screen with PROGRAM_PROVIDER=stub.
// Its fixtures are generated from tests/fixtures/kissj/korbo/ by bin/korbo-fixtures.php;
// edit the generator's source data, not fixtures/, and regenerate.
//
// Korbo has no visual identity in this repo, so it borrows the reference event's palette
// and its light role set rather than inventing colours: obrok19's are hand-authored and
// already measured by ThemingTest. Light-only, like obrok19 — no dark set, no toggle.
$reference = require __DIR__ . '/../obrok19/config.php';

return [
    'name' => 'Korbo 2026',
    'shortName' => 'Korbo',
    'features' => ['programs'],

    'colors' => $reference['colors'],
    'roles' => ['light' => $reference['roles']['light']],

    // no brand assets: the layout falls back to no logo when these are empty
    'assets' => ['menuLogo' => '', 'mainLogo' => ''],

    'homepage' => [
        'footerText' => '',
        'footerLinkLabel' => '',
        'footerLinkHref' => '',
    ],
];
