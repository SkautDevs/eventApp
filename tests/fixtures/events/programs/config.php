<?php

// A fixture event carrying only the Program screen. Its programme fixture holds the
// records no real event may hold — a name that is markup, a duplicate id, a start that
// does not parse — so the screen can be tested against hostile data without any of it
// living under events/.
return [
    'name' => 'Program Test Event',
    'features' => ['programs'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => '', 'mainLogo' => ''],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
    'sections' => [
        1 => ['id' => 1, 'title' => 'Sekce'],
    ],
];
