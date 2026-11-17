<?php

// A fixture event long before its start whose programme registration window has not
// opened yet (it opens in 2097, the event is in 2098): the home screen's "before" phase
// with the countdown and the window's start, whatever year the tests run in.
return [
    'name' => 'Window Before Event',
    'shortName' => 'Předem',
    'dates' => ['start' => '2098-06-02', 'end' => '2098-06-05'],
    'features' => ['programs', 'info', 'push'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => 'events/obrok19/Obrok19_minilogo.png', 'mainLogo' => 'events/obrok19/Obrok19_tmave_logo_800.png'],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
];
