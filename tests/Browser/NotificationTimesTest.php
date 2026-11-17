<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Push\MessageRepository;
use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\Attributes\Group;

/**
 * The notifications as a copy read later sees them: every relative time and Domů's date
 * heading are redrawn by www/shell.js from the device clock (window.pgClock here), with the
 * server's bands (NewsItems::relative()) on the Prague calendar. And a notification card is
 * text, read from the left.
 */
#[Group('browser')]
final class NotificationTimesTest extends BrowserTestCase
{
    private static function seed(string $title, string $pragueTime): void
    {
        $pdo = Database::open(dirname(__DIR__, 2) . '/' . self::DATABASE);
        (new Migrator())->migrate($pdo);
        (new MessageRepository($pdo))->add(
            event: 'korbo26',
            programmeId: null,
            targetLabel: 'Všem',
            title: $title,
            body: 'Zpráva z testu.',
            signature: 'test',
            sent: 1,
            removed: 0,
            unreached: null,
            at: new \DateTimeImmutable($pragueTime, new \DateTimeZone('Europe/Prague')),
        );
    }

    /** The relative time of the visible card titled $title. */
    private static function when(string $title): ?string
    {
        return self::script(<<<'JS'
            const card = [...document.querySelectorAll('main > [data-screen]:not([hidden]) .news')]
                .find(el => el.querySelector('strong').textContent === arguments[0]);
            return card ? card.querySelector('.news-when').textContent : null;
            JS, [$title]);
    }

    private static function clock(string $atom): void
    {
        self::script('const at = arguments[0]; window.pgClock = function () { return Date.parse(at); }; document.dispatchEvent(new CustomEvent("pg:tick"));', [$atom]);
    }

    public function testTheRelativeTimesFollowTheDeviceClock(): void
    {
        self::seed('Pozdě večer', '2027-05-20 23:50:00');
        self::seed('Dopoledne', '2027-05-20 10:00:00');
        // the server rendered these against its own today; the page redraws them at once
        self::pinClock('2027-05-21T00:10:00+02:00');
        self::visit('/korbo26/informace');
        self::waitFor('return document.readyState === "complete";');

        // minutes win over the calendar, across midnight; the day before is "včera"
        self::assertSame('před 20 min', self::when('Pozdě večer'));
        self::assertSame('včera 10:00', self::when('Dopoledne'));

        self::clock('2027-05-20T23:50:30+02:00');
        self::assertSame('právě teď', self::when('Pozdě večer'));
        self::assertSame('před 13 h', self::when('Dopoledne'));

        self::clock('2027-05-23T08:00:00+02:00');
        self::assertSame('20. 5. 23:50', self::when('Pozdě večer'));
        self::assertSame('20. 5. 10:00', self::when('Dopoledne'));
    }

    /** Domů's heading names the device's day, not the one the copy was fetched on. */
    public function testTheHomeDateFollowsTheDeviceClock(): void
    {
        // korbo26 is over, so Domů shows the day and today's notifications
        self::pinClock('2027-05-21T09:00:00+02:00');
        self::visit('/korbo26/');
        self::waitFor('return document.readyState === "complete";');

        self::assertSame('Pátek 21. 5.', self::script('return document.querySelector("[data-home-date]").textContent;'));
        // Prague's midnight, whatever the phone's zone
        self::clock('2027-05-22T23:30:00+02:00');
        self::assertSame('Sobota 22. 5.', self::script('return document.querySelector("[data-home-date]").textContent;'));
        self::clock('2027-05-23T00:30:00+02:00');
        self::assertSame('Neděle 23. 5.', self::script('return document.querySelector("[data-home-date]").textContent;'));
    }

    /** A notification card reads from the left, on Informace and on Domů. */
    public function testTheNotificationCardsAreLeftAligned(): void
    {
        self::seed('Dnešní zpráva', (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s'));

        foreach (['/korbo26/informace', '/korbo26/'] as $path) {
            self::visit($path);
            self::waitFor('return document.readyState === "complete";');
            $alignment = self::script(<<<'JS'
                const card = [...document.querySelectorAll('main > [data-screen]:not([hidden]) .news')]
                    .find(el => el.querySelector('strong').textContent === 'Dnešní zpráva');
                return card ? [getComputedStyle(card.querySelector('p')).textAlign, getComputedStyle(card.querySelector('.news-body')).textAlign] : null;
                JS);
            self::assertSame(['left', 'left'], $alignment, $path);
        }
    }
}
