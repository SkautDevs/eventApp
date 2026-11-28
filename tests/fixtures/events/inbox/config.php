<?php

// A fixture event with the participants' inbox: the emergency event's contact, with
// `inbox` enabled and its promise line in content/inbox.php (InboxPageTest, InboxSendTest).
// fixtures/registered.json makes participant 42 one the local source knows, so a page that
// reads the participant (Informace) keeps them logged in.
return [
    'name' => 'Inbox Test Event',
    'features' => ['info', 'push', 'inbox'],
    'colors' => ['background' => '#ffffff', 'link' => '#0000ee', 'base' => '#000000', 'darker' => '#000000', 'primary' => '#000000', 'text' => '#000000', 'text-invert' => '#ffffff'],
    'assets' => ['menuLogo' => '', 'mainLogo' => ''],
    'homepage' => ['footerText' => '', 'footerLinkLabel' => '', 'footerLinkHref' => ''],
];
