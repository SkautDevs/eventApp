<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\ProgramProviderInterface;
use App\Program\LocalProgramProvider;
use App\Push\MessageRepository;
use App\Toast\Toasts;

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
        return new class (new LocalProgramProvider(dirname(__DIR__, 2) . '/events/' . $slug . '/fixtures', $this->stubState)) extends DelegatingProvider {
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
        // the server's relative time, and the instant www/js/ui/reltime.js redraws it from
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
        self::assertStringNotContainsString('tab-avatar', $html);
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
        self::assertStringContainsString('tab-avatar', $html);
        self::assertStringNotContainsString('Propojení s KissJ už neplatí', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    /** The toast markup of a page, or null when it carries none. */
    private static function toast(string $html): ?string
    {
        return preg_match('#<div class="toast" role="status" data-toast data-key="toast">.*?</div>#s', $html, $m) === 1 ? $m[0] : null;
    }

    /** Leaves a toast in the test's session, as a write does before its redirect. */
    private static function leaveToast(\Slim\App $app): void
    {
        $toasts = $app->getContainer()?->get(Toasts::class);
        self::assertInstanceOf(Toasts::class, $toasts);
        $toasts->put('Uloženo', 'Druhý řádek');
    }

    /**
     * The loader's warm-up (X-Screen) and the worker's fill (X-Worker-Fill) read Informace
     * too, the worker because it is in the precache; neither is the reader looking, so the
     * toast waits for the next full page.
     */
    public function testAFragmentOrTheWorkersFillLeavesTheToastForTheNextFullPage(): void
    {
        $app = $this->app();
        self::leaveToast($app);

        foreach (['X-Screen', Toasts::WORKER_FILL_HEADER] as $header) {
            $response = $this->request($app, 'GET', '/informace', null, [$header => '1']);
            self::assertSame(200, $response->getStatusCode(), $header);
            self::assertNull(self::toast((string) $response->getBody()), $header);
            self::assertSame('', $response->getHeaderLine(Toasts::HEADER), $header);
        }

        self::assertStringContainsString('<strong>Uloženo</strong><span>Druhý řádek</span>', (string) self::toast((string) $this->request($app, 'GET', '/informace')->getBody()));
    }

    /** Any write's toast, not only a sent message's, is said on the first full Informace, once. */
    public function testAnotherWritesToastIsShownOnceOnTheFirstFullPage(): void
    {
        $app = $this->app();
        $toasts = $app->getContainer()?->get(Toasts::class);
        self::assertInstanceOf(Toasts::class, $toasts);
        $toasts->loggedIn();

        $first = $this->request($app, 'GET', '/informace');
        self::assertStringContainsString('<p><strong>Jsi přihlášen</strong></p>', (string) self::toast((string) $first->getBody()));
        self::assertSame('1', $first->getHeaderLine(Toasts::HEADER));

        $second = $this->request($app, 'GET', '/informace');
        self::assertNull(self::toast((string) $second->getBody()));
        self::assertSame('', $second->getHeaderLine(Toasts::HEADER));
    }

    /**
     * The toast is taken before the participant read: a read that finds the session gone
     * clears whatever waits in the session, and the toast the write left must still be said.
     */
    public function testAToastWaitingWhenTheSessionIsGoneIsStillShown(): void
    {
        $provider = $this->failingAfterPairing('korbo26');
        $app = $this->app('korbo26', [ProgramProviderInterface::class => $provider]);
        $this->pair($app);
        $this->request($app, 'GET', '/programy'); // the login's own toast, said
        $provider->failure = new \App\Program\SessionExpiredException();
        self::leaveToast($app);

        $response = $this->request($app, 'GET', '/informace');
        $html = (string) $response->getBody();

        self::assertStringContainsString('<strong>Uloženo</strong><span>Druhý řádek</span>', (string) self::toast($html));
        self::assertSame('1', $response->getHeaderLine(Toasts::HEADER));
        self::assertStringNotContainsString('tab-avatar', $html, 'the page itself renders logged out');
    }

    public function testAnEventWithoutInboxHasNoCard(): void
    {
        $app = $this->createApp('emergency', [MessageRepository::class => $this->messages], fixtureEvent: true);
        $html = (string) $this->request($app, 'GET', '/informace')->getBody();

        self::assertStringContainsString('Krizový kontakt akce', $html, 'the event has the contact the card would follow');

        self::assertStringNotContainsString('data-key="inbox-card"', $html);
    }

    /** @return string the inbox card's `<a>` element, asserted to be the only one */
    private static function inboxCard(string $html): string
    {
        self::assertSame(1, preg_match_all('#<a class="link-card[^"]*"[^>]*data-key="inbox-card"[^>]*>.*?</a>#s', $html, $cards));

        return $cards[0][0];
    }

    public function testTheInboxCardFollowsTheCrisisContactAndLeadsToTheForm(): void
    {
        $app = $this->createApp('inbox', [MessageRepository::class => $this->messages], fixtureEvent: true);
        $this->loginAs($app, 42, 'Kája');

        $html = (string) $this->request($app, 'GET', '/informace')->getBody();
        $card = self::inboxCard($html);

        self::assertStringContainsString('href="/inbox/zpravy"', $card);
        self::assertStringContainsString('Napiš organizátorům', $card);
        self::assertStringContainsString('<span class="link-card-note">Dotaz, problém nebo nápad. Když jde o zdraví nebo bezpečí, volej krizový kontakt.</span>', $card);
        self::assertStringNotContainsString('Napsat můžeš po přihlášení.', $card);
        $at = strpos($html, 'data-key="inbox-card"');
        self::assertGreaterThan((int) strpos($html, 'id="info-contact-title"'), $at);
        self::assertLessThan((int) strpos($html, '<h2>Notifikace</h2>'), $at);
    }

    public function testLoggedOutTheInboxCardLeadsToProfil(): void
    {
        $app = $this->createApp('inbox', [MessageRepository::class => $this->messages], fixtureEvent: true);

        $card = self::inboxCard((string) $this->request($app, 'GET', '/informace')->getBody());

        self::assertStringContainsString('href="/inbox/profil"', $card);
        self::assertStringContainsString('Napiš organizátorům', $card);
        self::assertStringContainsString('<span class="link-card-note">Dotaz, problém nebo nápad. Když jde o zdraví nebo bezpečí, volej krizový kontakt.</span>', $card);
        self::assertStringContainsString('<span class="link-card-note">Napsat můžeš po přihlášení.</span>', $card);
    }

    public function testNovinkyAndOdkazyAreGone(): void
    {
        $app = $this->app();

        self::assertSame(404, $this->request($app, 'GET', '/novinky')->getStatusCode());
        self::assertSame(404, $this->request($app, 'GET', '/odkazy')->getStatusCode());
    }

    public function testTheTabBarHasFiveTabs(): void
    {
        $html = (string) $this->request($this->app(), 'GET', '/informace')->getBody();

        preg_match('#<nav class="tabbar"[^>]*>(.*?)</nav>#s', $html, $nav);
        preg_match_all('#<a class="tab[^"]*" href="([^"]+)"[^>]*>.*?<span>([^<]+)</span>#s', $nav[1], $tabs);
        self::assertSame(['/obrok27/', '/obrok27/programy', '/obrok27/mapa', '/obrok27/informace', '/obrok27/profil'], $tabs[1]);
        self::assertSame(['Program', 'Místa', 'Informace', 'Profil'], array_slice($tabs[2], 1));
        self::assertStringContainsString('class="tab is-active" href="/obrok27/informace" aria-current="page"', $html);
        self::assertStringContainsString('<i class="fas fa-info-circle" aria-hidden="true"></i>', $nav[1]);
        // logged out, the Profil tab holds the person icon
        self::assertMatchesRegularExpression('#<a class="tab tab-profile" href="/obrok27/profil">\s*<i class="fas fa-user-circle" aria-hidden="true"></i>#', $nav[1]);
    }

    public function testThePrecacheNamesTheFourScreensProfilTheMessageFormAndOffline(): void
    {
        $list = json_decode((string) $this->request($this->app(), 'GET', '/precache.json')->getBody(), true);

        self::assertSame(
            ['/obrok27/', '/obrok27/programy', '/obrok27/mapa', '/obrok27/informace', '/obrok27/profil', '/obrok27/zpravy', '/obrok27/offline'],
            $list['documents'],
        );
    }
}
