<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

/**
 * The meadow with no signal. The network is taken away by stopping the PHP server: CDP's
 * offline emulation does not reach the service worker's own fetches, so it could prove
 * nothing here.
 */
#[Group('browser')]
final class OfflineTest extends BrowserTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // the previous test took it away
        self::startServer();
    }

    public function testEverySeenScreenOpensWithoutTheServer(): void
    {
        self::visit('/korbo26/');
        self::waitForPrecache('korbo26');

        self::stopServer();
        self::$browser->reload();
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/"]\'); return s && !s.hidden;');

        foreach (['/korbo26/programy' => '.tl-card', '/korbo26/novinky' => 'p'] as $path => $content) {
            self::tap('.tabbar a[href="' . $path . '"]');
            self::waitFor(
                'const s = document.querySelector(\'[data-screen="\' + arguments[0] + \'"]\'); return s && !s.hidden && s.querySelector(arguments[1]) !== null;',
                [$path, $content],
            );
        }
        self::tap('.appbar-profile');
        self::waitFor('const s = document.querySelector(\'[data-screen="/korbo26/profil"]\'); return s && !s.hidden;');

        // a page the worker never kept
        self::visit('/korbo26/neni');
        self::waitFor('return document.body.textContent.includes(arguments[0]);', ['Jsi offline a tahle stránka ještě není uložená.']);
    }

    /** Review Focus 1: the purge on login must not leave the reader with nothing offline. */
    public function testALoginIsCarriedIntoTheOfflineCopy(): void
    {
        self::visit('/korbo26/profil');
        $cache = self::waitForPrecache('korbo26');
        self::$browser->findElement(WebDriverBy::cssSelector('[data-screen="/korbo26/profil"] input[name="tieCode"]'))->sendKeys('KORBO1');
        self::tap('[data-screen="/korbo26/profil"] button[type="submit"]');
        self::waitFor('const who = document.querySelector(".appbar-who"); return who !== null && who.textContent === "TIE KORBO1";');

        // the Program page the worker refilled after the purge is the logged-in one
        self::waitForAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0])
                .then(cache => cache.match('/korbo26/programy', {ignoreVary: true}))
                .then(response => (response ? response.text() : ''))
                .then(html => done(html.includes('is-registered')), () => done(false));
            JS, [$cache]);

        self::stopServer();
        self::visit('/korbo26/programy');
        self::waitFor('return document.querySelector(".tl-card.is-registered") !== null;');
        self::assertSame('TIE KORBO1', self::script('return document.querySelector(".appbar-who").textContent;'));
    }
}
