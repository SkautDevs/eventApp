<?php

// A fixture event with the Google embed alone and no plan: Mapa's original markup.
return [
    'name' => 'Map Embed Test Event',
    'features' => ['map'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => '', 'mainLogo' => ''],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
    'map' => [
        'embedUrl' => 'https://www.google.com/maps/d/u/1/embed?mid=test',
    ],
];
