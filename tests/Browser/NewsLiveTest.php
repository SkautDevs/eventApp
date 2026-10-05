<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Push\MessageRepository;
use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\Attributes\Group;

/** A push arrives while Novinky is open: the message appears without a reload. */
#[Group('browser')]
final class NewsLiveTest extends BrowserTestCase
{
    public function testAPushBringsTheNewMessageWithoutAReload(): void
    {
        self::visit('/korbo26/novinky');
        self::script('window.__noReload = 1;');

        $pdo = Database::open(dirname(__DIR__, 2) . '/' . self::DATABASE);
        (new Migrator())->migrate($pdo);
        (new MessageRepository($pdo))->add(
            event: 'korbo26',
            programmeId: null,
            targetLabel: 'Všem',
            title: 'Zpráva z testu',
            body: 'Nástup v 18:00 u stožáru.',
            signature: 'test',
            sent: 1,
            removed: 0,
            unreached: null,
        );

        // what the worker posts after it has shown the notification
        self::script("navigator.serviceWorker.dispatchEvent(new MessageEvent('message', {data: {type: 'news-updated', programme: null}}));");

        self::waitFor('return [...document.querySelectorAll(\'[data-screen="/korbo26/novinky"] .news strong\')].some(el => el.textContent === arguments[0]);', ['Zpráva z testu']);
        self::assertSame(1, self::script('return window.__noReload;'));
    }
}
