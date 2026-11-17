<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\Pairing;
use App\Program\PairingPoll;
use App\Program\Participant;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\SessionExpiredException;
use App\Program\StubProgramProvider;
use Slim\App;

/**
 * Pairing is the only login: Profil starts it, the page polls eventApp, eventApp asks the
 * source, and an approval stores the device session token in the server-side session. The
 * local source approves on the first poll, so here a pairing is two requests.
 */
final class PairingTest extends AppTestCase
{
    /** korbo26's fixture participant 1 (registered.json): real registrations, no preregistered programme. */
    private const array MINE = [5, 10, 26, 31, 45, 50];

    private function korbo(?ProgramProviderInterface $provider = null): App
    {
        return $this->createApp('korbo26', $provider === null ? [] : [ProgramProviderInterface::class => $provider]);
    }

    private function counted(string $slug = 'korbo26'): CountingProgramProvider
    {
        return new CountingProgramProvider(new StubProgramProvider(dirname(__DIR__, 2) . '/events/' . $slug . '/fixtures', $this->stubState));
    }

    private function html(App $app, string $path): string
    {
        return (string) $this->request($app, 'GET', $path)->getBody();
    }

    /** @return array<string, mixed> */
    private function poll(App $app): array
    {
        $response = $this->request($app, 'GET', '/profil/propojit/stav');
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    // --- the start ---

    public function testTheStartShowsTheCodeTheKissjLinkAndTheWaitingLine(): void
    {
        $app = $this->korbo();
        $response = $this->request($app, 'POST', '/profil/propojit');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/korbo26/profil', $response->getHeaderLine('Location'));

        $html = $this->html($app, '/profil');
        self::assertStringContainsString('<p class="pairing-code" data-pairing-poll>483 291</p>', $html);
        self::assertStringContainsString('<p>Zadej kód v KissJ, nebo</p>', $html);
        self::assertMatchesRegularExpression('#<a class="btn btn-primary" href="https://kissj\.example/pairing" target="_blank" rel="noopener">Otevřít KissJ ↗</a>#u', $html);
        // its own class: www/push.js hides every .push-status while it has no push status to show
        self::assertStringContainsString('<p class="pairing-status" role="status" data-pairing-status>Čekám na potvrzení v KissJ…</p>', $html);
        self::assertStringNotContainsString('class="push-status" role="status" data-pairing-status', $html);
        self::assertStringContainsString('Začít znovu', $html);
        self::assertStringNotContainsString('Nejsi přihlášen', $html);
    }

    public function testTheStartSendsTheHashOfTheSessionIdAsTheDeviceId(): void
    {
        $provider = new class ($this->counted()) extends DelegatingProvider {
            /** @var list<string> */
            public array $seen = [];

            public function startPairing(string $deviceId): Pairing
            {
                $this->seen[] = $deviceId;

                return parent::startPairing($deviceId);
            }
        };
        $app = $this->korbo($provider);
        $this->request($app, 'POST', '/profil/propojit');
        $this->request($app, 'POST', '/profil/propojit');
        $seen = $provider->seen;

        self::assertCount(2, $seen);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $seen[0]);
        // one device: a second start replaces its own pending pairing at kissj
        self::assertSame($seen[0], $seen[1]);
    }

    public function testLoggedOutProfileInvitesThePairing(): void
    {
        $html = $this->html($this->korbo(), '/profil');

        self::assertStringContainsString('<h2>Nejsi přihlášen</h2>', $html);
        self::assertStringContainsString('Obsah můžeš jen prohlížet. Pro výběr programů a další akce je třeba být registrovaným účastníkem této akce a přihlásit se:', $html);
        self::assertMatchesRegularExpression('#<form method="post" action="/korbo26/profil/propojit"[^>]*>\s*<button type="submit" class="btn btn-primary">Přihlásit kódem z KissJ</button>#u', $html);
        self::assertStringContainsString('<title>Profil · ', $html);
    }

    public function testProfilLinksNoEventWebsite(): void
    {
        $html = $this->html($this->createApp('obrok27'), '/profil');

        self::assertStringNotContainsString('Nejsi ještě registrovaný účastník', $html);
        self::assertStringNotContainsString('https://obrok.skaut.cz/', $html);
    }

    public function testACrossSiteStartIs403AndStartsNothing(): void
    {
        $provider = $this->counted();
        $app = $this->korbo($provider);
        $response = $this->request($app, 'POST', '/profil/propojit', [], ['Sec-Fetch-Site' => 'cross-site']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Přihlášení se nepodařilo. Načti stránku a zkus to znovu.', (string) $response->getBody());
        self::assertSame(0, $provider->pairingStarts);
        self::assertStringContainsString('Nejsi přihlášen', $this->html($app, '/profil'));
    }

    public function testThreeHundredStartsFromOneAddressMakeTheNextOneWait(): void
    {
        $provider = $this->counted();
        $app = $this->korbo($provider);
        for ($i = 1; $i <= 300; $i++) {
            $this->request($app, 'POST', '/profil/propojit');
        }
        self::assertSame(300, $provider->pairingStarts);
        // a new reader behind the same address
        $_SESSION = [];

        $response = $this->request($app, 'POST', '/profil/propojit');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(300, $provider->pairingStarts, 'the 301st start must not reach the source');
        $html = $this->html($app, '/profil');
        self::assertStringContainsString('Teď se propojuje moc lidí najednou, zkus to za chvíli.', $html);
        self::assertStringNotContainsString('data-pairing-poll', $html);
        // reading it consumes nothing: a warm-up or a refill of Profil cannot swallow it
        self::assertStringContainsString('Teď se propojuje moc lidí najednou', $this->html($app, '/profil'));
    }

    public function testAFailedStartExplainsItself(): void
    {
        $provider = new class ($this->counted()) extends DelegatingProvider {
            public function startPairing(string $deviceId): Pairing
            {
                throw new \App\Program\ProgramUnavailableException('down');
            }
        };
        $app = $this->korbo($provider);
        self::assertSame(302, $this->request($app, 'POST', '/profil/propojit')->getStatusCode());

        $html = $this->html($app, '/profil');
        self::assertStringContainsString('Propojení se teď nedaří, zkus to prosím později.', $html);
        self::assertStringNotContainsString('data-pairing-poll', $html);
        self::assertStringContainsString('Přihlásit kódem z KissJ', $html);
    }

    // --- the poll ---

    public function testTheFirstPollApprovesAndThePagesShowWhoIsPaired(): void
    {
        $app = $this->korbo();
        $this->request($app, 'POST', '/profil/propojit');

        self::assertSame(['status' => 'approved'], $this->poll($app));

        $html = $this->html($app, '/profil');
        self::assertStringContainsString('<span class="appbar-avatar" aria-hidden="true">JN</span>', $html);
        self::assertStringContainsString('<meta name="push-identity" content="1">', $html);
        self::assertMatchesRegularExpression('#<h2 class="profile-who"><span class="avatar">JN</span> Jana Nováková</h2>#u', $html);
        self::assertStringContainsString('Odhlásit se z aplikace', $html);
        self::assertStringContainsString('data-confirm="Opravdu odhlásit?"', $html);
        self::assertStringNotContainsString('data-pairing-poll', $html);
        self::assertStringNotContainsString('Nejsi přihlášen', $html);
        // the install card with the notifications button, its install half hidden by itself
        self::assertStringContainsString('<section class="install-card" aria-label="Instalace" data-install-card="profile">', $html);
        self::assertMatchesRegularExpression('#<p class="push-enable">\s*<button type="button" class="btn btn-primary" data-push-toggle>\s*Povolit notifikace\s*</button>\s*</p>#', $html);
        self::assertStringContainsString('<p class="push-status" role="status" hidden></p>', $html);
        // the identity is the app bar's on every screen, not Profil's only
        self::assertStringContainsString('<span class="appbar-avatar" aria-hidden="true">JN</span>', $this->html($app, '/'));
    }

    /**
     * Review Focus: the login toast is consumed by the next full document the reader asks
     * for only. The loader's warm-up reads Profil as a fragment, and the worker's fill and
     * refill read it both as a fragment and as a whole document (with X-Worker-Fill); none
     * of them may swallow it.
     */
    public function testTheApprovalIsAToastOnTheNextFullProfilOnly(): void
    {
        $app = $this->korbo();
        $this->pair($app);

        $fragment = (string) $this->request($app, 'GET', '/profil', headers: ['X-Screen' => '1'])->getBody();
        self::assertStringNotContainsString('data-toast', $fragment, 'a fragment leaves the toast for the document');
        $filled = $this->request($app, 'GET', '/profil', headers: ['X-Worker-Fill' => '1']);
        self::assertStringNotContainsString('data-toast', (string) $filled->getBody(), "the worker's fetch of the page leaves it too");
        self::assertFalse($filled->hasHeader('X-Toast'));

        $response = $this->request($app, 'GET', '/profil');
        $first = (string) $response->getBody();
        self::assertStringContainsString('<div class="toast" role="status" data-toast data-key="toast"><p><strong>Jsi přihlášen</strong></p>', $first);
        // the first child of the screen's content, right after its heading
        self::assertMatchesRegularExpression('~<h1 class="sr-only">Profil</h1>\s*<div class="toast"~', $first);
        // marked, so the worker keeps it out of the offline copy
        self::assertSame('1', $response->getHeaderLine('X-Toast'));

        $second = $this->request($app, 'GET', '/profil');
        self::assertStringNotContainsString('data-toast', (string) $second->getBody(), 'shown once');
        self::assertFalse($second->hasHeader('X-Toast'), 'a page without a toast is not marked');
    }

    /** A toast that waited longer than a minute for its page is dropped unsaid. */
    public function testAToastOlderThanAMinuteIsDropped(): void
    {
        $app = $this->korbo();
        $_SESSION['korbo26']['toast'] = ['title' => 'Jsi přihlášen', 'line' => null, 'at' => time() - 61];

        $response = $this->request($app, 'GET', '/profil');

        self::assertStringNotContainsString('data-toast', (string) $response->getBody());
        self::assertFalse($response->hasHeader('X-Toast'));
        self::assertArrayNotHasKey('toast', $_SESSION['korbo26']);

        $_SESSION['korbo26']['toast'] = ['title' => 'Jsi přihlášen', 'line' => null, 'at' => time() - 30];
        self::assertStringContainsString('<strong>Jsi přihlášen</strong>', $this->html($app, '/profil'), 'half a minute is still in time');
    }

    public function testTheLogoutIsAToastToo(): void
    {
        $app = $this->korbo();
        $this->pair($app);
        $this->html($app, '/profil');

        $this->request($app, 'POST', '/profil/odpojit');

        $html = $this->html($app, '/profil');
        self::assertStringContainsString('<p><strong>Odhlášeno</strong><span>Obsah teď můžeš jen prohlížet</span></p>', $html);
        self::assertStringContainsString('Nejsi přihlášen', $html);
        self::assertStringNotContainsString('Jsi přihlášen<', $html);
        self::assertStringNotContainsString('data-toast', $this->html($app, '/profil'));
    }

    /** "Odhlášeno" only after a logout of somebody: a logged-out reader's POST says nothing. */
    public function testALogoutWithNobodyLoggedInIsNoToast(): void
    {
        $app = $this->korbo();

        self::assertSame(302, $this->request($app, 'POST', '/profil/odpojit')->getStatusCode());

        $html = $this->html($app, '/profil');
        self::assertStringNotContainsString('data-toast', $html);
        self::assertStringNotContainsString('Odhlášeno', $html);
        self::assertStringContainsString('Nejsi přihlášen', $html);
    }

    /** A malformed toast in the session (an older shape, a hand-edited file) renders nothing and goes. */
    public function testAMalformedToastIsDropped(): void
    {
        $app = $this->korbo();
        $_SESSION['korbo26']['toast'] = ['title' => 42];

        self::assertStringNotContainsString('data-toast', $this->html($app, '/profil'));
        self::assertArrayNotHasKey('toast', $_SESSION['korbo26']);
    }

    public function testAPollAfterTheApprovalHasNothingToCollect(): void
    {
        $app = $this->korbo();
        $this->pair($app);

        self::assertSame(['status' => 'expired'], $this->poll($app));
        // and it logs nobody out
        self::assertStringContainsString('<span class="appbar-avatar" aria-hidden="true">JN</span>', $this->html($app, '/profil'));
    }

    public function testAPollWithoutAPairingIsExpired(): void
    {
        self::assertSame(['status' => 'expired'], $this->poll($this->korbo()));
    }

    public function testThePollIsNeverCached(): void
    {
        $app = $this->korbo();
        $this->request($app, 'POST', '/profil/propojit');
        $response = $this->request($app, 'GET', '/profil/propojit/stav');

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $this->request($this->korbo(), 'GET', '/profil/propojit/stav')->getHeaderLine('Cache-Control'));
    }

    public function testAParticipantWithoutANicknameGetsTheIconAndAGenericName(): void
    {
        $app = $this->createApp('obrok27');
        $this->pair($app);
        $html = $this->html($app, '/profil');

        self::assertStringNotContainsString('appbar-avatar', $html);
        self::assertStringNotContainsString('class="avatar"', $html);
        // on Profil the link is lit like the current tab
        self::assertMatchesRegularExpression('#<a class="appbar-profile is-active" href="/obrok27/profil" aria-current="page">\s*<span class="sr-only">Profil</span>\s*<i class="fas fa-user-circle" aria-hidden="true"></i>#u', $html);
        // and nowhere else
        self::assertStringContainsString('<a class="appbar-profile" href="/obrok27/profil">', $this->html($app, '/programy'));
        self::assertMatchesRegularExpression('#<h2 class="profile-who"><i class="fas fa-user-circle" aria-hidden="true"></i> Účastník</h2>#u', $html);
        self::assertStringContainsString('<meta name="push-identity" content="1">', $html);
    }

    public function testNoPageNamesATieCode(): void
    {
        $app = $this->korbo();
        foreach (['/', '/profil', '/programy', '/informace'] as $path) {
            self::assertStringNotContainsString('TIE', $this->html($app, $path), $path . ' logged out');
        }
        $this->request($app, 'POST', '/profil/propojit');
        self::assertStringNotContainsString('TIE', $this->html($app, '/profil'), 'pending');
        $this->poll($app);
        foreach (['/', '/profil', '/programy', '/informace'] as $path) {
            self::assertStringNotContainsString('TIE', $this->html($app, $path), $path . ' paired');
        }
    }

    public function testProgramMarksTheParticipantsProgrammes(): void
    {
        $app = $this->korbo();
        $this->pair($app);

        self::assertSame(count(self::MINE), substr_count($this->html($app, '/programy'), '<p class="sheet-mine">'));
    }

    /** An outcome line is the explanation of a moment: ten minutes on it is gone. */
    public function testAnOutcomeLineIsGoneAfterTenMinutes(): void
    {
        $app = $this->korbo(new class ($this->counted()) extends DelegatingProvider {
            public function pollPairing(string $deviceCode): PairingPoll
            {
                return new PairingPoll(\App\Program\PairingStatus::Expired);
            }
        });
        $this->request($app, 'POST', '/profil/propojit');
        $this->poll($app);
        self::assertStringContainsString('vypršel', $this->html($app, '/profil'));

        $_SESSION['korbo26']['pairingOutcome']['at'] -= 9 * 60;
        self::assertStringContainsString('vypršel', $this->html($app, '/profil'), 'nine minutes on');
        $_SESSION['korbo26']['pairingOutcome']['at'] -= 61;
        self::assertStringNotContainsString('vypršel', $this->html($app, '/profil'), 'ten minutes and a second on');
    }

    /** An approval ends whatever went before it: a paired Profil carries no old outcome either. */
    public function testAnApprovalClearsAnEarlierOutcome(): void
    {
        $app = $this->korbo();
        \App\Kernel::sessionGone($app->getContainer()->get(\App\Auth\Authenticator::class), $app->getContainer()->get(\App\Session::class));
        $this->pair($app);

        self::assertArrayNotHasKey('pairingOutcome', $_SESSION['korbo26']);
        $this->request($app, 'POST', '/profil/odpojit');
        self::assertStringNotContainsString('Propojení s KissJ už neplatí', $this->html($app, '/profil'));
    }

    // --- secrets stay on the server ---

    /** The session token and the device code live in the server-side session only; the user code is the one thing shown. */
    public function testNeitherTheTokenNorTheDeviceCodeIsEverRendered(): void
    {
        $app = $this->korbo();
        $this->request($app, 'POST', '/profil/propojit');
        $deviceCode = $_SESSION['korbo26']['pairing']['deviceCode'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $deviceCode);
        foreach ([[], ['X-Screen' => '1']] as $headers) {
            $pending = (string) $this->request($app, 'GET', '/profil', headers: $headers)->getBody();
            self::assertStringContainsString('483 291', $pending);
            self::assertStringNotContainsString($deviceCode, $pending);
        }

        $this->poll($app);
        $token = $_SESSION['korbo26']['identity']['sessionToken'];
        self::assertMatchesRegularExpression('/^stub-1-[0-9a-f]{16}$/', $token);
        foreach (['/', '/profil', '/programy', '/informace'] as $path) {
            foreach ([[], ['X-Screen' => '1']] as $headers) {
                $html = (string) $this->request($app, 'GET', $path, headers: $headers)->getBody();
                self::assertStringNotContainsString($token, $html, $path);
                self::assertStringNotContainsString($deviceCode, $html, $path);
            }
        }
    }

    /** A paired reader's start starts nothing: no pairing at kissj, no attempt counted. */
    public function testAStartWhilePairedChangesNothing(): void
    {
        $provider = $this->counted();
        $app = $this->korbo($provider);
        $this->pair($app);
        $token = $_SESSION['korbo26']['identity']['sessionToken'];

        $response = $this->request($app, 'POST', '/profil/propojit');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/korbo26/profil', $response->getHeaderLine('Location'));
        self::assertSame(1, $provider->pairingStarts, 'only the pairing that logged in');
        self::assertArrayNotHasKey('pairing', $_SESSION['korbo26']);
        self::assertSame($token, $_SESSION['korbo26']['identity']['sessionToken']);
        self::assertSame(1, (int) $app->getContainer()->get(\PDO::class)->query('SELECT COUNT(*) FROM pairing_attempts')->fetchColumn(), 'only the start that paired was counted');
    }

    public function testAnExpiredCodeSaysSo(): void
    {
        $app = $this->korbo(new class ($this->counted()) extends DelegatingProvider {
            public function pollPairing(string $deviceCode): PairingPoll
            {
                return new PairingPoll(\App\Program\PairingStatus::Expired);
            }
        });
        $this->request($app, 'POST', '/profil/propojit');

        self::assertSame(['status' => 'expired'], $this->poll($app));
        self::assertStringContainsString('Kód vypršel, začni znovu.', $this->html($app, '/profil'));
        // the pairing is gone with it, and asking again changes nothing
        self::assertSame(['status' => 'expired'], $this->poll($app));
        self::assertStringContainsString('vypršel', $this->html($app, '/profil'), 'the line is still there');
        self::assertStringContainsString(
            'vypršel',
            (string) $this->request($app, 'GET', '/profil', headers: ['X-Screen' => '1'])->getBody(),
            'and in the fragment',
        );

        // a new start is a new attempt: the old outcome goes
        $this->request($app, 'POST', '/profil/propojit');
        self::assertStringNotContainsString('vypršel', $this->html($app, '/profil'));
        self::assertArrayNotHasKey('pairingOutcome', $_SESSION['korbo26']);
    }

    public function testPendingAndSlowDownKeepThePairing(): void
    {
        $statuses = [\App\Program\PairingStatus::Pending, \App\Program\PairingStatus::SlowDown];
        $app = $this->korbo(new class ($this->counted(), $statuses) extends DelegatingProvider {
            public function __construct(ProgramProviderInterface $inner, private array $statuses)
            {
                parent::__construct($inner);
            }

            public function pollPairing(string $deviceCode): PairingPoll
            {
                $next = array_shift($this->statuses);

                return $next === null ? parent::pollPairing($deviceCode) : new PairingPoll($next);
            }
        });
        $this->request($app, 'POST', '/profil/propojit');

        self::assertSame(['status' => 'pending'], $this->poll($app));
        self::assertSame(['status' => 'slowDown'], $this->poll($app));
        self::assertStringContainsString('data-pairing-poll', $this->html($app, '/profil'));
        self::assertSame(['status' => 'approved'], $this->poll($app));
    }

    public function testAFailedParticipantReadAfterTheApprovalKeepsPolling(): void
    {
        $provider = new class ($this->counted()) extends DelegatingProvider {
            public int $failures = 1;

            public function getParticipant(string $sessionToken): Participant
            {
                if ($this->failures-- > 0) {
                    throw new \App\Program\ProgramUnavailableException('down');
                }

                return parent::getParticipant($sessionToken);
            }
        };
        $app = $this->korbo($provider);
        $this->request($app, 'POST', '/profil/propojit');

        $response = $this->request($app, 'GET', '/profil/propojit/stav');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"pending"}', (string) $response->getBody());
        self::assertNull($this->auth($app)->identity());
        self::assertStringContainsString('data-pairing-poll', $this->html($app, '/profil'));

        // kissj re-issues the approval within a minute: the next poll collects it
        self::assertSame(['status' => 'approved'], $this->poll($app));
        self::assertSame(1, $this->auth($app)->identity()?->participantId);
    }

    public function testAFailedPollKeepsThePairingAndExplainsNothing(): void
    {
        $provider = new class ($this->counted()) extends DelegatingProvider {
            public int $failures = 1;

            public function pollPairing(string $deviceCode): PairingPoll
            {
                if ($this->failures-- > 0) {
                    throw new \App\Program\ProgramUnavailableException('down');
                }

                return parent::pollPairing($deviceCode);
            }
        };
        $app = $this->korbo($provider);
        $this->request($app, 'POST', '/profil/propojit');

        $response = $this->request($app, 'GET', '/profil/propojit/stav');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"pending"}', (string) $response->getBody());
        $html = $this->html($app, '/profil');
        self::assertStringContainsString('483 291', $html);
        self::assertStringNotContainsString('<p class="notice">', $html);
        self::assertStringNotContainsString('nedaří', $html);

        self::assertSame(['status' => 'approved'], $this->poll($app));
    }

    public function testAMalformedPollAnswerIsAFailedPollToo(): void
    {
        $app = $this->korbo(new class ($this->counted()) extends DelegatingProvider {
            public function pollPairing(string $deviceCode): PairingPoll
            {
                throw new ProgramDataException('not a poll answer');
            }
        });
        $this->request($app, 'POST', '/profil/propojit');

        self::assertSame(['status' => 'pending'], $this->poll($app));
        self::assertStringContainsString('483 291', $this->html($app, '/profil'));
    }

    // --- the logout ---

    public function testTheLogoutRevokesAndForgetsTheParticipant(): void
    {
        $provider = $this->counted();
        $app = $this->korbo($provider);
        $this->pair($app);

        $response = $this->request($app, 'POST', '/profil/odpojit');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/korbo26/profil', $response->getHeaderLine('Location'));
        self::assertSame(1, $provider->revokes);

        $programs = $this->html($app, '/programy');
        self::assertStringNotContainsString('<p class="sheet-mine">', $programs);
        self::assertStringNotContainsString('appbar-avatar', $programs);
        self::assertStringContainsString('Nejsi přihlášen', $this->html($app, '/profil'));
    }

    public function testAFailedRevokeStillLogsOut(): void
    {
        $app = $this->korbo(new class ($this->counted()) extends DelegatingProvider {
            public function revokeSession(string $sessionToken): void
            {
                throw new SessionExpiredException();
            }
        });
        $this->pair($app);
        $this->request($app, 'POST', '/profil/odpojit');

        self::assertStringContainsString('Nejsi přihlášen', $this->html($app, '/profil'));
    }

    /**
     * A revoke kissj answers with 401 found the session already gone: that is the outcome the
     * logout wanted, not a fault, so nothing is filed. Any other failure is.
     */
    public function testARevokeOfAnExpiredSessionIsNotReportedButAnOutageIs(): void
    {
        foreach ([[new SessionExpiredException(), 0], [new \App\Program\ProgramUnavailableException('down'), 1]] as [$failure, $expected]) {
            $_SESSION = [];
            $app = $this->korbo(new class ($this->counted(), $failure) extends DelegatingProvider {
                public function __construct(ProgramProviderInterface $inner, private readonly \Throwable $failure)
                {
                    parent::__construct($inner);
                }

                public function revokeSession(string $sessionToken): void
                {
                    throw $this->failure;
                }
            });
            $this->pair($app);

            $captured = $this->capturingSentry(function () use ($app): void {
                $this->request($app, 'POST', '/profil/odpojit');
            });

            self::assertCount($expected, $captured, $failure::class);
            self::assertStringContainsString('Nejsi přihlášen', $this->html($app, '/profil'), $failure::class);
        }
    }

    /**
     * Runs $fn with a Sentry hub whose client records every exception it is handed.
     *
     * @return list<\Throwable>
     */
    private function capturingSentry(\Closure $fn): array
    {
        $captured = [];
        $client = self::createStub(\Sentry\ClientInterface::class);
        // tracing off, so the transaction middleware starts nothing
        $client->method('getOptions')->willReturn(new \Sentry\Options(['traces_sample_rate' => null]));
        $client->method('captureException')->willReturnCallback(static function (\Throwable $e) use (&$captured) {
            $captured[] = $e;

            return null;
        });
        $previous = \Sentry\SentrySdk::getCurrentHub();
        \Sentry\SentrySdk::setCurrentHub(new \Sentry\State\Hub($client));
        try {
            $fn();
        } finally {
            \Sentry\SentrySdk::setCurrentHub($previous);
        }

        return $captured;
    }

    public function testACrossSiteLogoutIs403AndKeepsTheReaderLoggedIn(): void
    {
        $provider = $this->counted();
        $app = $this->korbo($provider);
        $this->pair($app);

        $response = $this->request($app, 'POST', '/profil/odpojit', [], ['Sec-Fetch-Site' => 'cross-site']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $provider->revokes);
        self::assertStringContainsString('appbar-avatar', $this->html($app, '/profil'));
    }

    public function testAnExpiredSessionOnProgramLogsOut(): void
    {
        $app = $this->korbo(new class ($this->counted()) extends DelegatingProvider {
            public function getParticipant(string $sessionToken): Participant
            {
                throw new SessionExpiredException();
            }
        });
        $this->loginAs($app, 1, 'Jana Nováková');

        $html = $this->html($app, '/programy');
        self::assertStringContainsString('Propojení s KissJ už neplatí, přihlas se znovu.', $html);
        self::assertStringNotContainsString('appbar-avatar', $html);
        self::assertNull($this->auth($app)->identity());
    }

    public function testTheOldTieRoutesAreGone(): void
    {
        $app = $this->korbo();

        self::assertSame(404, $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'KORBO1'])->getStatusCode());
        self::assertSame(404, $this->request($app, 'GET', '/profil/tie')->getStatusCode());
    }

    public function testPairingOneEventDoesNotLogIntoAnother(): void
    {
        $this->pair($this->korbo());

        self::assertStringContainsString('Nejsi přihlášen', $this->html($this->createApp('obrok27'), '/profil'));
    }

    private function auth(App $app): \App\Auth\Authenticator
    {
        return $app->getContainer()->get(\App\Auth\Authenticator::class);
    }
}
