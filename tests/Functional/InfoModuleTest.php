<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\ProgramProviderInterface;
use App\Program\StubProgramProvider;
use App\Push\MessageRepository;

/** Informace: the crisis contact, every notification under day headings, and the quick links. */
final class InfoModuleTest extends AppTestCase
{
    private MessageRepository $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messages = new MessageRepository(self::memoryDb());
    }

    private function app(string $slug = 'obrok27', array $overrides = []): \Slim\App
    {
        return $this->createApp($slug, overrides: $overrides + [MessageRepository::class => $this->messages]);
    }

    private function registeredId(string $slug): int
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/events/' . $slug . '/fixtures/registered.json'), true)['1'][0];
    }

    /** A provider whose participant read can be made to fail after the pairing went through. */
    private function failingAfterPairing(string $slug): DelegatingProvider
    {
        return new class (new StubProgramProvider(dirname(__DIR__, 2) . '/events/' . $slug . '/fixtures', $this->stubState)) extends DelegatingProvider {
            public ?\Throwable $failure = null;

            public function getParticipant(string $sessionToken): \App\Program\Participant
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return parent::getParticipant($sessionToken);
            }
        };
    }

    public function testTheCrisisContactCallsAtOneTap(): void
    {
        $html = (string) $this->request($this->app(), 'GET', '/informace')->getBody();

        self::assertStringContainsString('<section class="emergency info-contact" aria-labelledby="info-contact-title">', $html);
        self::assertStringContainsString('<h2 id="info-contact-title">Krizový kontakt akce</h2>', $html);
        self::assertStringContainsString('+420 704 444 015', $html);
        self::assertMatchesRegularExpression('#<a class="btn btn-primary emergency-call" href="tel:\+420704444015">\s*Zavolat#', $html);
        // first on the screen, above the notifications and the links
        self::assertLessThan(strpos($html, '<h2>Notifikace</h2>'), strpos($html, 'Krizový kontakt akce'));
        self::assertLessThan(strpos($html, '<h2>Rychlé odkazy</h2>'), strpos($html, '<h2>Notifikace</h2>'));
    }

    /** The spaces of a number written the way people write it go; the + stays. */
    public function testThePhoneNumberIsDialable(): void
    {
        $html = (string) $this->request($this->createApp('emergency', fixtureEvent: true), 'GET', '/informace')->getBody();

        self::assertStringContainsString('href="tel:+420000000000"', $html);
        self::assertStringContainsString('<span class="emergency-number">+420 000 000 000</span>', $html);
    }

    public function testAnEventWithoutAnEmergencyFileShowsNoContact(): void
    {
        $html = (string) $this->request($this->app('miquik26'), 'GET', '/informace')->getBody();

        self::assertSame(200, $this->request($this->app('miquik26'), 'GET', '/informace')->getStatusCode());
        self::assertStringNotContainsString('class="emergency', $html);
        self::assertStringContainsString('<h2>Notifikace</h2>', $html);
    }

    public function testTheQuickLinksAreCardsWithTheHandbookFirst(): void
    {
        $html = (string) $this->request($this->app(), 'GET', '/informace')->getBody();

        self::assertStringContainsString('<h2>Rychlé odkazy</h2>', $html);
        self::assertMatchesRegularExpression('#<ul class="link-list">\s*<li>\s*<a class="link-card is-highlight" href="/obrok27/handbook/download"#', $html);
        // an external link opens in a new tab and carries the outward mark, hidden from the accessibility tree
        self::assertStringContainsString('href="https://obrok.skaut.cz/" target="_blank" rel="noopener"', $html);
        self::assertStringContainsString('obrok.skaut.cz', $html);
        self::assertStringContainsString('<span class="sr-only"> (otevře se v nové záložce)</span>', $html);
        self::assertStringContainsString('class="link-card-out fas fa-external-link-alt" aria-hidden="true"', $html);
        self::assertDoesNotMatchRegularExpression('#href="[^"]*handbook[^"]*" target=#', $html);
    }

    public function testNoMessagesSaysSo(): void
    {
        $html = (string) $this->request($this->app(), 'GET', '/informace')->getBody();

        self::assertStringContainsString('Zatím žádné notifikace.', $html);
        self::assertStringNotContainsString('news-day', $html);
    }

    public function testMessagesAreGroupedByDayNewestFirst(): void
    {
        // first: the app sets the process's zone to Prague, which the stamps are written in
        $app = $this->app();
        $this->messages->add('obrok27', null, 'Všem', 'Starší zpráva', 'a', 'Lung', 0, 0, null, new \DateTimeImmutable('-2 days'));
        $this->messages->add('obrok27', null, 'Všem', 'Novější zpráva', "řádek\nřádek", 'Lung', 0, 0, null);

        $html = (string) $this->request($app, 'GET', '/informace')->getBody();

        self::assertSame(2, preg_match_all('#<section class="news-day" data-key="news-(\d{8})">\s*<h3>[^<]+</h3>#', $html, $days));
        self::assertSame(
            [(new \DateTimeImmutable())->format('Ymd'), (new \DateTimeImmutable('-2 days'))->format('Ymd')],
            $days[1],
        );
        self::assertLessThan(strpos($html, 'Starší zpráva'), strpos($html, 'Novější zpráva'));
        self::assertMatchesRegularExpression('#<div class="news" data-key="message-\d+">#', $html);
        // the server's relative time, and the instant www/shell.js redraws it from
        self::assertMatchesRegularExpression('#<small class="news-when" data-sent-at="\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+0[12]:00">právě teď</small>#', $html);
        // the full body, its line breaks kept for the CSS
        self::assertStringContainsString("<p class=\"news-body\">řádek\nřádek</p>", $html);
        // the signature is the log's, never the reader's
        self::assertStringNotContainsString('Lung', $html);
        self::assertStringNotContainsString('Zatím žádné notifikace.', $html);
    }

    public function testHiddenMessagesAreNotShownAndMarkupIsText(): void
    {
        $hidden = $this->messages->add('obrok27', null, 'Všem', 'Překlep', 'a', 'Lung', 0, 0, null);
        $this->messages->setHidden('obrok27', $hidden, true, 'Lung');
        $this->messages->add('obrok27', null, 'Všem', '<b>tučně</b>', 'a', 'Lung', 0, 0, null);

        $html = (string) $this->request($this->app(), 'GET', '/informace')->getBody();

        self::assertStringNotContainsString('Překlep', $html);
        self::assertStringContainsString('&lt;b&gt;tučně&lt;/b&gt;', $html);
    }

    public function testAProgrammeMessageIsOnlyForItsRegisteredParticipants(): void
    {
        $this->messages->add('obrok27', $this->registeredId('obrok27'), 'Program', 'Jen pro přihlášené', 'a', 'Lung', 0, 0, 0);
        $this->messages->add('obrok27', 999999, 'Cizí', 'Cizí program', 'a', 'Lung', 0, 0, 0);
        $app = $this->app();

        self::assertStringNotContainsString('Jen pro přihlášené', (string) $this->request($app, 'GET', '/informace')->getBody());

        $this->pair($app);
        $html = (string) $this->request($app, 'GET', '/informace')->getBody();
        self::assertStringContainsString('Jen pro přihlášené', $html);
        self::assertStringNotContainsString('Cizí program', $html);
    }

    /** kissj's 401 on the participant read: logged out, the message is everybody's view again, and Profil says why. */
    public function testAnExpiredSessionLogsOutAndProfilSaysWhy(): void
    {
        $this->messages->add('korbo26', $this->registeredId('korbo26'), 'Program', 'Jen pro přihlášené', 'a', 'Lung', 0, 0, 0);
        $provider = $this->failingAfterPairing('korbo26');
        $app = $this->app('korbo26', [ProgramProviderInterface::class => $provider]);
        $this->pair($app);
        $provider->failure = new \App\Program\SessionExpiredException();

        $html = (string) $this->request($app, 'GET', '/informace')->getBody();
        self::assertStringNotContainsString('Jen pro přihlášené', $html);
        self::assertStringNotContainsString('appbar-avatar', $html);
        self::assertStringContainsString('Propojení s KissJ už neplatí, přihlas se znovu.', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    /** An outage logs nobody out: the programme messages are simply left out. */
    public function testAnOutageKeepsTheReaderLoggedIn(): void
    {
        $this->messages->add('korbo26', $this->registeredId('korbo26'), 'Program', 'Jen pro přihlášené', 'a', 'Lung', 0, 0, 0);
        $this->messages->add('korbo26', null, 'Všem', 'Pro všechny', 'a', 'Lung', 0, 0, null);
        $provider = $this->failingAfterPairing('korbo26');
        $app = $this->app('korbo26', [ProgramProviderInterface::class => $provider]);
        $this->pair($app);
        $provider->failure = new \App\Program\ProgramUnavailableException('kissj is down');

        $response = $this->request($app, 'GET', '/informace');
        $html = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Pro všechny', $html);
        self::assertStringNotContainsString('Jen pro přihlášené', $html);
        self::assertStringContainsString('appbar-avatar', $html);
        self::assertStringNotContainsString('Propojení s KissJ už neplatí', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    public function testNovinkyAndOdkazyAreGone(): void
    {
        $app = $this->app();

        self::assertSame(404, $this->request($app, 'GET', '/novinky')->getStatusCode());
        self::assertSame(404, $this->request($app, 'GET', '/odkazy')->getStatusCode());
    }

    public function testTheTabBarHasFourTabs(): void
    {
        $html = (string) $this->request($this->app(), 'GET', '/informace')->getBody();

        preg_match('#<nav class="tabbar"[^>]*>(.*?)</nav>#s', $html, $nav);
        preg_match_all('#<a class="tab[^"]*" href="([^"]+)"[^>]*>.*?<span>([^<]+)</span>#s', $nav[1], $tabs);
        self::assertSame(['/obrok27/', '/obrok27/programy', '/obrok27/mapa', '/obrok27/informace'], $tabs[1]);
        self::assertSame(['Program', 'Místa', 'Informace'], array_slice($tabs[2], 1));
        self::assertStringContainsString('class="tab is-active" href="/obrok27/informace" aria-current="page"', $html);
        self::assertStringContainsString('<i class="fas fa-info-circle" aria-hidden="true"></i>', $nav[1]);
    }

    public function testThePrecacheNamesTheFourScreensProfilAndOffline(): void
    {
        $list = json_decode((string) $this->request($this->app(), 'GET', '/precache.json')->getBody(), true);

        self::assertSame(
            ['/obrok27/', '/obrok27/programy', '/obrok27/mapa', '/obrok27/informace', '/obrok27/profil', '/obrok27/offline'],
            $list['documents'],
        );
    }
}
