<?php

// map-places without the Google embed: a plan-only event, which cannot show a place that
// lies outside its plan's bounds.
return [
    'name' => 'Map Places Plan Test Event',
    'features' => ['map', 'programs'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => '', 'mainLogo' => 'events/korbo26/logo_korbo_1272.png'],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
    'map' => [
        'image' => 'events/obrok19/plan-example.png',
        'planBounds' => ['north' => 50.01, 'south' => 50.00, 'west' => 15.00, 'east' => 15.02],
    ],
];
