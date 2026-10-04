<?php

// An unlisted fixture event whose config extends the Content-Security-Policy.
return [
    'name' => 'CSP Test Event',
    'features' => ['news', 'push'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => '', 'mainLogo' => ''],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
    'csp' => ['img-src' => ['https://photos.example'], 'frame-src' => ['https://video.example']],
];
