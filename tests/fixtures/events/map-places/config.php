<?php

// A fixture event with both maps and places: the Google embed, a plan with planBounds, and
// programmes at places inside the bounds, outside them and without GPS (fixtures/). The
// plan is any file that exists under www/; the bounds are a square of made-up degrees.
return [
    'name' => 'Map Places Test Event',
    'features' => ['map', 'programs'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => '', 'mainLogo' => 'events/korbo26/logo_korbo_1272.png'],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
    'map' => [
        'embedUrl' => 'https://www.google.com/maps/d/embed?mid=test',
        'image' => 'events/obrok19/plan-example.png',
        'planBounds' => ['north' => 50.01, 'south' => 50.00, 'west' => 15.00, 'east' => 15.02],
    ],
];
