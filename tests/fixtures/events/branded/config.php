<?php

return [
    'name' => 'Branded Test Event',
    'features' => ['news', 'push'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => [
        'menuLogo' => 'events/branded/menu.svg',
        'menuLogoOnState' => 'events/branded/menu-on-state.svg',
        'appBarLogo' => 'events/branded/app-bar.svg',
        'mainLogo' => '',
        'stylesheet' => 'sw.js',
    ],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
];
