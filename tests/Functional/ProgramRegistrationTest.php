<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Module\ProgramsModule;
use App\Program\ProgramProviderInterface;
use App\Program\RegistrationRefusedException;
use App\Program\RegistrationWindow;
use App\Program\SessionExpiredException;
use App\Program\StubProgramProvider;
use App\Program\ProgramUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * Registration from the programme's sheet: a form POST that calls the provider, leaves a
 * toast in the session and redirects to the programme's deep link, where the next full
 * Program document says what happened. korbo26's fixture participant 1 is registered for
 * 5, 10, 26, 31, 45 and 50; 3, 36 and 51 are preregistered, and as in kissj in nobody's
 * list; its window is open from 2020 to 2099.
 */
final class ProgramRegistrationTest extends AppTestCase
{
    /** Volejbal, st 16. 9. 08:00–22:00 on Volejbalové hřiště, section 1 (Pohybová): not the participant's. */
    private const int FREE = 1;

    /** One of the participant's own, not preregistered. */
    private const int MINE = 5;

    private const int PREREGISTERED = 51;

    /**
     * korbo26 on the local source, with any of its calls replaced: `$write` runs instead of
     * register and unregister, `$window` instead of the window read and `$participant`
     * instead of the participant read.
     */
    private function korbo(?\Closure $write = null, ?\Closure $window = null, ?\Closure $participant = null): App
    {
        if ($write === null && $window === null && $participant === null) {
            return $this->createApp('korbo26');
        }
        $inner = new StubProgramProvider($this->eventsDir() . '/korbo26/fixtures', $this->stubState);
        $provider = new class ($inner, $write, $window, $participant) extends DelegatingProvider {
            public function __construct(
                ProgramProviderInterface $inner,
                private readonly ?\Closure $write,
                private readonly ?\Closure $window,
                private readonly ?\Closure $participant,
            ) {
                parent::__construct($inner);
            }

            public function register(string $sessionToken, int $programmeId): void
            {
                $this->write === null ? parent::register($sessionToken, $programmeId) : ($this->write)();
            }

            public function unregister(string $sessionToken, int $programmeId): void
            {
                $this->write === null ? parent::unregister($sessionToken, $programmeId) : ($this->write)();
            }

            public function getRegistrationWindow(): RegistrationWindow
            {
                return $this->window === null ? parent::getRegistrationWindow() : ($this->window)();
            }

            public function getParticipant(string $sessionToken): \App\Program\Participant
            {
                return $this->participant === null ? parent::getParticipant($sessionToken) : ($this->participant)();
            }
        };

        return $this->createApp('korbo26', [ProgramProviderInterface::class => $provider]);
    }

    private function html(App $app, string $path = '/programy', array $headers = []): string
    {
        return (string) $this->request($app, 'GET', $path, null, $headers)->getBody();
    }

    /** The toast markup of a page, or null when it carries none. */
    private static function toast(string $html): ?string
    {
        return preg_match('#<div class="toast" role="status" data-toast data-key="toast">.*?</div>#s', $html, $m) === 1 ? $m[0] : null;
    }

    /**
     * The sheet footer's content of one programme, exactly as rendered: not trimmed, because
     * whitespace in an empty footer is what would stop `.sheet-footer:empty` hiding it.
     */
    private static function sheet(string $html, int $id): string
    {
        self::assertSame(1, preg_match('#<div class="sheet-body" data-key="' . $id . '" data-pg-detail="' . $id . '".*?<div class="sheet-footer">(.*?)</div>#s', $html, $m), "no footer for {$id}");

        return $m[1];
    }

    private static function cardClasses(string $html, int $id): string
    {
        self::assertSame(1, preg_match('#<button type="button" class="(tl-card[^"]*)" data-key="' . $id . '"#', $html, $m));

        return $m[1];
    }

    private function programmeName(int $id): string
    {
        foreach (json_decode((string) file_get_contents($this->eventsDir() . '/korbo26/fixtures/programs.json'), true) as $programme) {
            if ($programme['id'] === $id) {
                return $programme['name'];
            }
        }
        self::fail("no programme {$id}");
    }

    private function post(App $app, int $id, string $action = 'prihlasit', array $headers = []): ResponseInterface
    {
        return $this->request($app, 'POST', '/programy/' . $id . '/' . $action, [], $headers);
    }

    public function testARegistrationRedirectsToTheDeepLinkAndSaysSoOnce(): void
    {
        $app = $this->korbo();
        $this->pair($app);

        $response = $this->post($app, self::FREE);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/korbo26/programy#section-1-program-1', $response->getHeaderLine('Location'));

        $page = $this->request($app, 'GET', '/programy');
        $html = (string) $page->getBody();
        $toast = self::toast($html);
        self::assertSame(
            '<div class="toast" role="status" data-toast data-key="toast"><p><strong>Přihlášen: Volejbal</strong><span>St 16. 9. · 8:00–22:00 · Volejbalové hřiště</span></p><button type="button" class="toast-close" data-toast-close aria-label="Zavřít">✕</button></div>',
            $toast,
        );
        // the section is the sheet's business, not the toast's
        self::assertStringNotContainsString('Pohybová', (string) $toast);
        // the worker keeps no copy of a page that carries a toast
        self::assertSame('1', $page->getHeaderLine('X-Toast'));
        self::assertStringContainsString('is-registered', self::cardClasses($html, self::FREE));
        // the toast is the first child of the Program root, before the strips
        self::assertMatchesRegularExpression('#data-view="timeline" data-step="next">\s*<div class="toast"#', $html);

        $again = $this->request($app, 'GET', '/programy');
        self::assertNull(self::toast((string) $again->getBody()));
        self::assertSame('', $again->getHeaderLine('X-Toast'));
    }

    public function testAFragmentBetweenTheRedirectAndTheDocumentLeavesTheToast(): void
    {
        $app = $this->korbo();
        $this->pair($app);
        $this->post($app, self::FREE);

        self::assertNull(self::toast($this->html($app, '/programy', ['X-Screen' => '1'])));
        self::assertNull(self::toast($this->html($app, '/programy', ['X-Worker-Fill' => '1'])));
        self::assertStringContainsString('Přihlášen: Volejbal', (string) self::toast($this->html($app)));
    }

    public function testAnUnregistrationSaysTheSeatIsFree(): void
    {
        $app = $this->korbo();
        $this->pair($app);
        self::assertStringContainsString('is-registered', self::cardClasses($this->html($app), self::MINE));

        $response = $this->post($app, self::MINE, 'odhlasit');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('-program-' . self::MINE, $response->getHeaderLine('Location'));

        $html = $this->html($app);
        self::assertStringContainsString('<strong>Odhlášen: ' . htmlspecialchars($this->programmeName(self::MINE)) . '</strong><span>Místo jsme uvolnili</span>', (string) self::toast($html));
        self::assertStringNotContainsString('is-registered', self::cardClasses($html, self::MINE));
    }

    public function testASecondRegistrationIsRefusedInCzech(): void
    {
        $app = $this->korbo();
        $this->pair($app);
        $this->post($app, self::FREE);
        $this->html($app);

        $this->post($app, self::FREE);
        self::assertStringContainsString('<p><strong>Tenhle program už máš.</strong></p>', (string) self::toast($this->html($app)));
    }

    public function testTheSheetFooterOffersWhatThePairedReaderCanDo(): void
    {
        $app = $this->korbo();
        $this->pair($app);
        $html = $this->html($app);

        self::assertSame(
            '<form method="post" action="/korbo26/programy/1/prihlasit" data-key="register" data-pg-write="1"><button class="btn btn-primary">Přihlásit se</button></form>',
            self::sheet($html, self::FREE),
        );
        self::assertSame(
            '<form method="post" action="/korbo26/programy/5/odhlasit" data-key="unregister" data-pg-write="5"><button class="btn" data-confirm="Opravdu odhlásit?">Odhlásit se z programu</button></form>',
            self::sheet($html, self::MINE),
        );
        // a preregistered programme shows no button, and is not dimmed
        self::assertSame('', self::sheet($html, self::PREREGISTERED));
        self::assertStringNotContainsString('/programy/51/', $html);
        self::assertStringNotContainsString('is-dimmed', self::cardClasses($html, self::PREREGISTERED));
        self::assertStringContainsString('is-dimmed', self::cardClasses($html, self::FREE));
    }

    /** A window that cannot be read leaves every footer empty: "register" would be a guess. */
    public function testAnUnreadableWindowOffersNoButton(): void
    {
        $app = $this->korbo(window: static fn () => throw new ProgramUnavailableException('kissj is down'));
        $this->pair($app);
        $html = $this->html($app);

        foreach ([self::FREE, self::MINE, self::PREREGISTERED] as $id) {
            self::assertSame('', self::sheet($html, $id), "programme {$id}");
        }
        self::assertStringNotContainsString('/prihlasit"', $html);
        self::assertStringNotContainsString('/odhlasit"', $html);
    }

    /** Paired, but the participant's own list could not be read: whose is what is a guess, so no button. */
    public function testAnUnreadPersonalListOffersNoButton(): void
    {
        $app = $this->korbo(participant: static fn () => throw new ProgramUnavailableException('kissj is down'));
        // a pairing reads the participant, so the identity is stored directly
        $this->loginAs($app);
        $html = $this->html($app);

        self::assertStringContainsString('Osobní program se nepodařilo načíst.', $html);
        foreach ([self::FREE, self::MINE, self::PREREGISTERED] as $id) {
            self::assertSame('', self::sheet($html, $id), "programme {$id}");
        }
        self::assertStringNotContainsString('/prihlasit"', $html);
        self::assertStringNotContainsString('/odhlasit"', $html);
    }

    /**
     * A double tap: both POSTs leave before the redirect is followed, and kissj refuses the
     * second as already done. The success the reader has not seen yet stays the toast.
     */
    public function testADoubleTapKeepsTheSuccessToast(): void
    {
        $app = $this->korbo();
        $this->pair($app);

        $this->post($app, self::FREE);
        self::assertSame(302, $this->post($app, self::FREE)->getStatusCode());
        self::assertStringContainsString('<strong>Přihlášen: Volejbal</strong>', (string) self::toast($this->html($app)));

        $this->post($app, self::FREE, 'odhlasit');
        $this->post($app, self::FREE, 'odhlasit');
        self::assertStringContainsString('<strong>Odhlášen: Volejbal</strong>', (string) self::toast($this->html($app)));
    }

    /** Only a repeat of the very write is quiet: another programme's refusal is still said. */
    public function testAnotherProgrammesRefusalReplacesTheWaitingToast(): void
    {
        $app = $this->korbo();
        $this->pair($app);

        $this->post($app, self::FREE);
        $this->post($app, self::MINE);
        self::assertStringContainsString('<p><strong>Tenhle program už máš.</strong></p>', (string) self::toast($this->html($app)));
    }

    public function testLoggedOutEveryFooterLinksToProfil(): void
    {
        $html = $this->html($this->korbo());

        foreach ([self::FREE, self::MINE, self::PREREGISTERED] as $id) {
            // not paired comes first, even before "preregistered"
            self::assertSame('<a class="btn" href="/korbo26/profil" data-key="unpaired">Pro přihlašování se přihlas kódem z KissJ</a>', self::sheet($html, $id), "programme {$id}");
        }
        self::assertStringNotContainsString('/prihlasit"', $html);
    }

    public function testNotPairedAPostIsRefused(): void
    {
        $response = $this->post($this->korbo(), self::FREE);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Pro přihlašování se přihlas kódem z KissJ.', (string) $response->getBody());
    }

    public function testACrossSitePostIsRefused(): void
    {
        $app = $this->korbo();
        $this->pair($app);

        $response = $this->post($app, self::FREE, 'prihlasit', ['Sec-Fetch-Site' => 'cross-site']);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('is-registered', self::cardClasses($this->html($app), self::FREE));

        $unregister = $this->post($app, self::MINE, 'odhlasit', ['Sec-Fetch-Site' => 'cross-site']);
        self::assertSame(403, $unregister->getStatusCode());
        self::assertStringContainsString('Odhlášení z programu se nepodařilo. Načti stránku a zkus to znovu.', (string) $unregister->getBody());
        self::assertStringContainsString('is-registered', self::cardClasses($this->html($app), self::MINE));
    }

    public function testBeforeTheWindowEveryFooterSaysWhenItOpens(): void
    {
        $app = $this->createApp('window-before', fixtureEvent: true);
        $this->pair($app);
        $html = $this->html($app);

        foreach ([1, 2] as $id) {
            self::assertSame('<p class="sheet-line" data-key="before">Přihlašování začne 15. 3. 2097 v 18:00</p>', self::sheet($html, $id), "programme {$id}");
        }
    }

    public function testBeforeTheWindowLoggedOutTheFooterLinksToProfil(): void
    {
        $html = $this->html($this->createApp('window-before', fixtureEvent: true));

        self::assertSame('<a class="btn" href="/window-before/profil" data-key="unpaired">Pro přihlašování se přihlas kódem z KissJ</a>', self::sheet($html, 1));
    }

    /** @return iterable<string, array{RegistrationWindow, string, string}> */
    public static function closedWindows(): iterable
    {
        yield 'after' => [new RegistrationWindow(new \DateTimeImmutable('2001-01-01T00:00:00+01:00'), new \DateTimeImmutable('2002-01-01T00:00:00+01:00')), 'after', 'Přihlašování skončilo'];
        yield 'none' => [new RegistrationWindow(null, null), 'none', 'Přihlašování zatím není otevřené'];
        yield 'before' => [new RegistrationWindow(new \DateTimeImmutable('2097-03-15T18:00:00+01:00'), new \DateTimeImmutable('2097-04-15T20:00:00+02:00')), 'before', 'Přihlašování začne 15. 3. 2097 v 18:00'];
    }

    /** Paired, a window that is not open says why, whatever the programme; no trailing period. */
    #[\PHPUnit\Framework\Attributes\DataProvider('closedWindows')]
    public function testAClosedWindowIsSaidWithoutATrailingPeriod(RegistrationWindow $window, string $state, string $line): void
    {
        $footer = static fn (array $detail): ?array => (new \ReflectionMethod(ProgramsModule::class, 'footer'))
            ->invoke(null, $detail, true, true, $window, new \DateTimeImmutable('2026-11-17T12:00:00+01:00'));

        self::assertSame(['state' => $state, 'line' => $line], $footer(['preregistered' => false, 'registered' => false]));
        self::assertSame(['state' => $state, 'line' => $line], $footer(['preregistered' => true, 'registered' => true]));
    }

    public function testARefusalCodeIsItsCzechLine(): void
    {
        $app = $this->korbo(static fn () => throw new RegistrationRefusedException('full'));
        $this->pair($app);
        $this->post($app, self::FREE);

        self::assertStringContainsString('<p><strong>Je plno.</strong></p>', (string) self::toast($this->html($app)));
    }

    public function testAnUnknownRefusalCodeIsTheGenericLine(): void
    {
        $app = $this->korbo(static fn () => throw new RegistrationRefusedException('somethingNew'));
        $this->pair($app);
        $this->post($app, self::FREE);

        self::assertStringContainsString('<p><strong>Přihlášení se nepodařilo.</strong></p>', (string) self::toast($this->html($app)));
    }

    public function testAnOutageIsSaidAsOne(): void
    {
        $app = $this->korbo(static fn () => throw new ProgramUnavailableException('kissj is down'));
        $this->pair($app);
        $response = $this->post($app, self::FREE);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('<p><strong>Přihlášení se teď nedaří, zkus to prosím později.</strong></p>', (string) self::toast($this->html($app)));
    }

    /** The unregister side says what it is: odhlášení, not přihlášení. */
    public function testAnUnregisterFailureIsWordedAsOne(): void
    {
        foreach ([
            'unknown code' => [new RegistrationRefusedException('somethingNew'), 'Odhlášení se nepodařilo.'],
            'known code' => [new RegistrationRefusedException('closed'), 'Přihlašování není otevřené.'],
            'outage' => [new ProgramUnavailableException('kissj is down'), 'Odhlášení se teď nedaří, zkus to prosím později.'],
        ] as $case => [$failure, $title]) {
            $app = $this->korbo(static fn () => throw $failure);
            // the session outlives the app within a test, so the identity is stored directly
            $this->loginAs($app);
            $response = $this->post($app, self::MINE, 'odhlasit');

            self::assertSame(302, $response->getStatusCode(), $case);
            self::assertStringContainsString('<p><strong>' . $title . '</strong></p>', (string) self::toast($this->html($app)), $case);
        }
    }

    public function testAnUnregisterOnAGoneSessionLogsTheReaderOut(): void
    {
        $app = $this->korbo(static fn () => throw new SessionExpiredException());
        $this->pair($app);
        $this->post($app, self::MINE, 'odhlasit');

        $html = $this->html($app);
        self::assertStringContainsString('Propojení s KissJ už neplatí, přihlas se znovu.', (string) self::toast($html));
        self::assertSame('<a class="btn" href="/korbo26/profil" data-key="unpaired">Pro přihlašování se přihlas kódem z KissJ</a>', self::sheet($html, self::MINE));
    }

    /** The local source's state file cannot be written: an outage toast, never a 500. */
    public function testAnUnwritableLocalStateIsAnOutage(): void
    {
        $file = $this->stubState . '/not-a-directory';
        touch($file);
        $provider = new StubProgramProvider($this->eventsDir() . '/korbo26/fixtures', $file . '/state');
        $app = $this->createApp('korbo26', [ProgramProviderInterface::class => $provider]);
        $this->pair($app);

        $response = $this->post($app, self::FREE);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('<p><strong>Přihlášení se teď nedaří, zkus to prosím později.</strong></p>', (string) self::toast($this->html($app)));
    }

    public function testAGoneSessionLogsTheReaderOut(): void
    {
        $app = $this->korbo(static fn () => throw new SessionExpiredException());
        $this->pair($app);
        $this->post($app, self::FREE);

        $html = $this->html($app);
        self::assertStringContainsString('Propojení s KissJ už neplatí, přihlas se znovu.', (string) self::toast($html));
        // logged out: the footers send the reader to Profil again
        self::assertSame('<a class="btn" href="/korbo26/profil" data-key="unpaired">Pro přihlašování se přihlas kódem z KissJ</a>', self::sheet($html, self::FREE));
    }

    /** When and where, as the toast says it: a run past midnight names its start day only. */
    public function testTheToastLine(): void
    {
        $programme = static fn (string $start, string $end, ?string $location): array => [
            'id' => 9, 'name' => 'X', 'section' => ['id' => 1], 'start' => ['date' => $start], 'end' => ['date' => $end], 'location' => $location,
        ];

        self::assertSame('Pá 21. 5. · 9:00–12:30 · Žlutý les', ProgramsModule::toastLine($programme('2027-05-21 09:00:00', '2027-05-21 12:30:00', 'Žlutý les')));
        self::assertSame('Čt 20. 5. · 22:00–1:30', ProgramsModule::toastLine($programme('2027-05-20 22:00:00', '2027-05-21 01:30:00', null)));
        self::assertSame('Út 18. 5. · 10:00–11:00', ProgramsModule::toastLine($programme('2027-05-18 10:00:00', '2027-05-18 11:00:00', '')));
    }
}
