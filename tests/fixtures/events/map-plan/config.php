<?php

// A fixture event with both maps: the Google embed and the handbook plan. The plan is
// any file that exists under www/; the browser tests use obrok19's sample plan instead.
return [
    'name' => 'Map Plan Test Event',
    'features' => ['map'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => '', 'mainLogo' => ''],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
    'map' => [
        'embedUrl' => 'https://www.google.com/maps/d/embed?mid=test',
        'image' => 'events/obrok19/Obrok19_minilogo.png',
        'imageAlt' => 'Plán testovacího areálu',
    ],
];
