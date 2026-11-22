<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\MessageRepository;
use Tests\CoreStylesheet;

/**
 * Domů. Which half of it a reader sees follows the event's dates, so every assertion that
 * depends on the phase runs on a fixture event whose dates never move: window-before and
 * window-open (2098, so always "before"), listed-past (2025) and korbo26 (2026, over for good).
 */
final class HomepageTest extends AppTestCase
{
    private const string PAIR_CARD = '<section class="link-card is-highlight pair-card" data-key="pair">';

    private function fixture(string $slug, array $overrides = []): \Slim\App
    {
        return $this->createApp($slug, $overrides, fixtureEvent: true);
    }

    public function testHomepageRenders(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Obrok 2019', $html);
        self::assertStringContainsString('Krizový telefon', $html);
    }

    public function testAnEventWithoutAFooterLinkRendersNoEmptyLink(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/')->getBody();
        self::assertStringNotContainsString('<a href=""></a>', $html);
    }

    public function testTheAppBarHomeLinkIsNamedForTheEvent(): void
    {
        $app = $this->createApp('korbo26');
        $name = \App\EventConfig::load($this->eventsDir(), 'korbo26')->name;
        self::assertStringContainsString('<span class="sr-only">' . htmlspecialchars($name) . ' – úvod</span>', (string) $this->request($app, 'GET', '/informace')->getBody());
    }

    public function testLongUrlsWrapInsteadOfBeingClipped(): void
    {
        $css = CoreStylesheet::read();
        foreach (['.news-body', '.sheet-perex', '.pl-perex'] as $selector) {
            self::assertMatchesRegularExpression('/' . preg_quote($selector, '/') . '\s*\{[^}]*overflow-wrap:\s*anywhere/', $css, $selector);
        }
    }

    /** The crisis contact moved to Informace (InfoModuleTest); Domů carries none. */
    public function testTheEmergencyBoxIsNotOnTheHomeScreen(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringNotContainsString('class="emergency', $html);
        self::assertStringNotContainsString('tel:', $html);
    }

    // --- before the start ------------------------------------------------------

    public function testBeforeTheStartTheCountdownAndTheWindowsStartShow(): void
    {
        $html = (string) $this->request($this->fixture('window-before'), 'GET', '/')->getBody();

        self::assertStringContainsString('<h2 class="home-kicker">Co tě čeká</h2>', $html);
        self::assertStringContainsString('<article class="link-card"><h3>Přihlašování na programy</h3><p>15. 3. 2097 · 18:00</p></article>', $html);
        self::assertMatchesRegularExpression('#<a class="link-card countdown" data-countdown data-start="2098-06-02" href="/window-before/programy"><h3>Potkáme se už za <span data-countdown-days>\d+</span> <span data-countdown-unit>(den|dny|dní)</span></h3><p>2\.–5\. 6\. 2098</p></a>#u', $html);
        self::assertStringNotContainsString('home-date', $html);
        self::assertStringNotContainsString('Starší notifikace', $html);
        self::assertStringNotContainsString('data-next-up', $html);
        // the window card comes before the countdown
        self::assertLessThan(strpos($html, 'data-countdown'), strpos($html, 'Přihlašování na programy'));
    }

    public function testWhileTheWindowIsOpenALoggedOutReaderIsToldWhereToLook(): void
    {
        $html = (string) $this->request($this->fixture('window-open'), 'GET', '/')->getBody();

        self::assertStringContainsString('<h3>Výběr programů</h3><p>Do 15. 4. 2097</p>', $html);
        self::assertStringContainsString('<p>Výběr programů je otevřený. Programy si můžeš prohlédnout v sekci Program.</p>', $html);
        self::assertStringNotContainsString('Vybrat programy', $html);
        self::assertStringNotContainsString('Přihlašování na programy', $html);
    }

    public function testWhileTheWindowIsOpenAPairedReaderGetsTheButton(): void
    {
        $app = $this->fixture('window-open');
        $this->pair($app);
        $html = (string) $this->request($app, 'GET', '/')->getBody();

        self::assertStringContainsString('<h3>Výběr programů</h3><p>Do 15. 4. 2097</p><a class="btn btn-primary" href="/window-open/programy/prehled">Vybrat programy</a>', $html);
        self::assertStringNotContainsString('Výběr programů je otevřený.', $html);
        // before the start there is no programme of the day to show
        self::assertStringNotContainsString('data-next-up', $html);
    }

    // --- from the start ----------------------------------------------------------

    public function testFromTheStartTodaysDateAndTheNotificationsShow(): void
    {
        $messages = new MessageRepository(self::memoryDb());
        // the app first: it sets the process's zone to Prague, which the stamps are written
        // in, so the run does not depend on the zone PHP started in
        $app = $this->fixture('listed-past', [MessageRepository::class => $messages]);
        $messages->add('listed-past', null, 'Všem', 'Dnešní zpráva', 'dnes', 'Lung', 0, 0, null);
        $messages->add('listed-past', null, 'Všem', 'Starší zpráva', 'předevčírem', 'Lung', 0, 0, null, new \DateTimeImmutable('-2 days'));
        $html = (string) $this->request($app, 'GET', '/')->getBody();

        // the server's day, and the device clock's once www/js/home/countdown.js redraws it
        self::assertMatchesRegularExpression('#<h2 class="home-date" data-home-date>(Pondělí|Úterý|Středa|Čtvrtek|Pátek|Sobota|Neděle) \d{1,2}\. \d{1,2}\.</h2>#u', $html);
        // today's as full cards, the same markup as on Informace; the older one only there
        self::assertMatchesRegularExpression('#<div class="news" data-key="message-\d+">\s*<p><strong>Dnešní zpráva</strong> <small class="news-when" data-sent-at="\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+0[12]:00">[^<]+</small></p>\s*<p class="news-body">dnes</p>#u', $html);
        self::assertStringNotContainsString('Starší zpráva', $html);
        self::assertStringContainsString('<a class="btn" href="/listed-past/informace">Starší notifikace</a>', $html);
        self::assertStringNotContainsString('class="emergency', $html);
        self::assertStringNotContainsString('data-countdown', $html);
        self::assertStringNotContainsString('Co tě čeká', $html);
    }

    public function testAnEventWithoutDatesRendersAsDuringIt(): void
    {
        $html = (string) $this->request($this->createApp('obrok19'), 'GET', '/')->getBody();

        self::assertStringContainsString('class="home-date"', $html);
        self::assertStringNotContainsString('data-countdown', $html);
    }

    // --- every phase ---------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function phases(): iterable
    {
        yield 'before, window not open' => ['window-before'];
        yield 'before, window open' => ['window-open'];
        yield 'after the end' => ['listed-past'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phases')]
    public function testALoggedOutReaderIsAskedToLogInInEveryPhase(string $slug): void
    {
        $html = (string) $this->request($this->fixture($slug), 'GET', '/')->getBody();
        $name = \App\EventConfig::load(dirname(__DIR__) . '/fixtures/events', $slug)->shortName ?? \App\EventConfig::load(dirname(__DIR__) . '/fixtures/events', $slug)->name;

        self::assertStringContainsString(self::PAIR_CARD . '<h2>Už jsi registrovaný na ' . htmlspecialchars($name) . '?</h2><p>Přihlas se údaji ze své registrace, ať nepřijdeš o novinky, výběr programů a upozornění v průběhu akce!</p><a class="btn btn-primary" href="/' . $slug . '/profil">Přihlásit se</a></section>', $html);
        // under the logo
        self::assertGreaterThan(strpos($html, 'mainLogo'), strpos($html, self::PAIR_CARD));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phases')]
    public function testAPairedReaderIsNotAskedToLogIn(string $slug): void
    {
        $app = $this->fixture($slug);
        $this->pair($app);

        self::assertStringNotContainsString('pair-card', (string) $this->request($app, 'GET', '/')->getBody());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phases')]
    public function testTheInstallCardCarriesBothButtonsInEveryPhase(string $slug): void
    {
        $html = (string) $this->request($this->fixture($slug), 'GET', '/')->getBody();
        $config = \App\EventConfig::load(dirname(__DIR__) . '/fixtures/events', $slug);
        $name = $config->shortName ?? $config->name;

        self::assertStringContainsString('<section class="install-card" aria-label="Instalace" data-install-card="home">', $html);
        self::assertStringContainsString('<h2>Ulož si ' . htmlspecialchars($name) . ' na plochu</h2>', $html);
        self::assertStringContainsString('<p>Zapni si upozornění, ať ti neuteče výběr programů, novinky a varování v průběhu akce.</p>', $html);
        self::assertStringContainsString('<button type="button" class="btn install-btn">Uložit jako aplikaci</button>', $html);
        self::assertMatchesRegularExpression('#<button type="button" class="btn btn-primary" data-push-toggle>\s*Povolit notifikace\s*</button>#', $html);
        self::assertStringNotContainsString('Aktivuj si notifikace', $html);
        // the app bar keeps the event's mark on Domů as on every screen
        self::assertStringContainsString('<img class="appbar-logo" src="events/obrok19/Obrok19_minilogo.png" alt="">', $html);
    }

    // --- Můj program -------------------------------------------------------------

    public function testAPairedParticipantsProgrammesWaitForTheDeviceClock(): void
    {
        $app = $this->createApp('korbo26');
        $this->pair($app);
        $html = (string) $this->request($app, 'GET', '/')->getBody();

        // all six of the fixture participant's programmes are there for the device clock;
        // the preregistered 3 and 36 are in no participant's list kissj sends (a known
        // limitation: which ones are this participant's needs their role from kissj)
        self::assertSame(6, substr_count($html, 'class="link-card is-highlight next-card"'));
        self::assertSame(6, preg_match_all('#class="link-card is-highlight next-card"[^>]* data-deep-link hidden>#', $html));
        // each card links to its programme's sheet in Moje, and 36 and 3 have none
        preg_match_all('#class="link-card is-highlight next-card" href="([^"]*)"#', $html, $hrefs);
        self::assertSame(
            [
                '/korbo26/programy/moje#section-6-program-5',
                '/korbo26/programy/moje#section-1-program-10',
                '/korbo26/programy/moje#section-1-program-26',
                '/korbo26/programy/moje#section-3-program-31',
                '/korbo26/programy/moje#section-3-program-45',
                '/korbo26/programy/moje#section-1-program-50',
            ],
            $hrefs[1],
        );
        // korbo26 is over: the server hides the box, the shell shows it for a clock in the camp
        self::assertStringContainsString('<section class="next-up" aria-label="Tvůj program" data-next-up hidden>', $html);
        self::assertStringContainsString('<h2>Můj program</h2>', $html);
        // 45: Street magic, 11:00–15:00 on 19 Sep at Nástěnka, in the section Kulturní
        self::assertMatchesRegularExpression('#<a class="link-card is-highlight next-card" href="/korbo26/programy/moje\#section-3-program-45" data-key="45" data-start="2026-09-19T11:00:00\+02:00" data-end="2026-09-19T15:00:00\+02:00" data-time="11:00" data-until="do 15:00" data-location="Nástěnka" data-deep-link hidden>#', $html);
        self::assertMatchesRegularExpression('#data-key="45".*?<p class="next-lead" data-next-lead></p>.*?<p class="next-section">Kulturní</p>#s', $html);
        // 31 has no location: no attribute, so the shell leaves the place out
        self::assertMatchesRegularExpression('#data-key="31" data-start="[^"]+" data-end="[^"]+" data-time="14:00" data-until="do 15:00" data-deep-link hidden>#', $html);
        // after today's notifications, before the install card
        self::assertLessThan(strpos($html, 'data-install-card'), strpos($html, 'data-next-up'));
        self::assertGreaterThan(strpos($html, 'home-date'), strpos($html, 'data-next-up'));
    }

    public function testLoggedOutThereIsNoNextProgramme(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/')->getBody();

        self::assertStringNotContainsString('data-next-up', $html);
    }

    /** kissj's 401 on the participant read: the reader is logged out and Profil says why. */
    public function testAnExpiredSessionLogsOutAndProfilSaysWhy(): void
    {
        $provider = new class (new \App\Program\LocalProgramProvider(dirname(__DIR__, 2) . '/events/korbo26/fixtures', $this->stubState)) extends DelegatingProvider {
            public bool $expired = false;

            public function getParticipant(string $sessionToken): \App\Program\Participant
            {
                if ($this->expired) {
                    throw new \App\Program\SessionExpiredException();
                }

                return parent::getParticipant($sessionToken);
            }
        };
        $app = $this->createApp('korbo26', [\App\Program\ProgramProviderInterface::class => $provider]);
        $this->pair($app);
        $provider->expired = true;

        $response = $this->request($app, 'GET', '/');
        $html = (string) $response->getBody();
        // marked, so the worker keeps this page out of the offline copy
        self::assertSame('1', $response->getHeaderLine('X-Toast'));
        self::assertStringNotContainsString('data-next-up', $html);
        self::assertStringNotContainsString('appbar-avatar', $html);
        // the page renders logged out, and says why in a toast (Task 13 left it to Task 14)
        self::assertStringContainsString(self::PAIR_CARD, $html);
        self::assertStringContainsString('<p><strong>Odhlášeno</strong><span>Propojení s KissJ už neplatí, přihlas se znovu.</span></p>', $html);
        // the loader's warm-up (a fragment) reads Profil first; the reader's own visit still sees the line
        $fragment = (string) $this->request($app, 'GET', '/profil', headers: ['X-Screen' => '1'])->getBody();
        self::assertStringContainsString('Propojení s KissJ už neplatí, přihlas se znovu.', $fragment);
        $profile = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Propojení s KissJ už neplatí, přihlas se znovu.', $profile);
        self::assertStringContainsString('Nejsi přihlášen', $profile);

        // a new pairing start is the reader acting on it: the line goes
        $this->request($app, 'POST', '/profil/propojit');
        self::assertStringNotContainsString('Propojení s KissJ už neplatí', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    /** An outage is not a logout: the cards are left out and the reader stays paired. */
    public function testAnOutageKeepsTheReaderPaired(): void
    {
        $provider = new class (new \App\Program\LocalProgramProvider(dirname(__DIR__, 2) . '/events/korbo26/fixtures', $this->stubState)) extends DelegatingProvider {
            public bool $down = false;

            public function getParticipant(string $sessionToken): \App\Program\Participant
            {
                if ($this->down) {
                    throw new \App\Program\ProgramUnavailableException('down');
                }

                return parent::getParticipant($sessionToken);
            }

            public function getSections(): array
            {
                if ($this->down) {
                    throw new \App\Program\ProgramUnavailableException('down');
                }

                return parent::getSections();
            }

            public function getRegistrationWindow(): \App\Program\RegistrationWindow
            {
                if ($this->down) {
                    throw new \App\Program\ProgramUnavailableException('down');
                }

                return parent::getRegistrationWindow();
            }
        };
        $app = $this->createApp('korbo26', [\App\Program\ProgramProviderInterface::class => $provider]);
        $this->pair($app);
        $provider->down = true;

        $response = $this->request($app, 'GET', '/');
        $html = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('data-next-up', $html);
        self::assertStringContainsString('<span class="appbar-avatar" aria-hidden="true">JN</span>', $html);
    }

    /** Before the start the window is the only provider read; its outage only drops the card. */
    public function testAWindowOutageLeavesTheCardOut(): void
    {
        $provider = new class (new \App\Program\LocalProgramProvider(dirname(__DIR__) . '/fixtures/events/window-before/fixtures', $this->stubState)) extends DelegatingProvider {
            public function getRegistrationWindow(): \App\Program\RegistrationWindow
            {
                throw new \App\Program\ProgramUnavailableException('down');
            }
        };
        $response = $this->request($this->fixture('window-before', [\App\Program\ProgramProviderInterface::class => $provider]), 'GET', '/');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Přihlašování na programy', $html);
        self::assertStringContainsString('data-countdown', $html);
    }
}
