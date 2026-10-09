<?php

// A fixture event with the handbook plan and no Google embed: one view, no pill.
return [
    'name' => 'Map Plan Only Test Event',
    'features' => ['map'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => '', 'mainLogo' => ''],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
    'map' => [
        'image' => 'events/obrok19/Obrok19_minilogo.png',
    ],
];
