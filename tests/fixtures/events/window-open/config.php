<?php

// A fixture event long before its start while the programme registration window is open
// (2020 to 2097, the event in 2098): the home screen's "before" phase with the open
// window card, whatever year the tests run in.
return [
    'name' => 'Window Open Event',
    'shortName' => 'Okno',
    'dates' => ['start' => '2098-06-02', 'end' => '2098-06-05'],
    'features' => ['programs', 'info', 'push'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => 'events/obrok19/Obrok19_minilogo.png', 'mainLogo' => 'events/obrok19/Obrok19_tmave_logo_800.png'],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
];
