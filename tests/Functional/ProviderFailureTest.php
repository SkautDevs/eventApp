<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\ProgramDataException;
use App\Program\Pairing;
use App\Program\ProgramProviderInterface;
use App\Program\SessionExpiredException;
use App\Program\LocalProgramProvider;

final class ProviderFailureTest extends AppTestCase
{
    public function testProgramsPageDegradesGracefullyOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            programsException: new \App\Program\ProgramUnavailableException('down'),
        );
        $app = $this->createApp(overrides: [ProgramProviderInterface::class => $provider]);

        $response = $this->request($app, 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'Programy se nepodařilo načíst, zkus to prosím později.',
            (string) $response->getBody(),
        );
    }

    public function testProgramsDegradeUnpersonalizedOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            identityException: new \App\Program\ProgramUnavailableException('down'),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
        ]);
        $this->loginAs($app, 1, 'Jana Nováková');

        // the participant read (during GET /programy) finds kissj not answering
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringContainsString('Osobní program se nepodařilo načíst.', $html);
        // the reader stays paired, just without a highlighted program
        self::assertStringContainsString('<span class="tab-avatar" aria-hidden="true">JN</span>', $html);
    }

    /** A pairing start kissj does not answer is explained on Profil, and nothing is pending. */
    public function testAPairingStartDegradesGracefullyOnProviderOutage(): void
    {
        $app = $this->createApp(overrides: [ProgramProviderInterface::class => $this->failingStart(new \App\Program\ProgramUnavailableException('down'))]);

        $start = $this->request($app, 'POST', '/profil/propojit');
        self::assertSame(302, $start->getStatusCode());

        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Propojení se teď nedaří, zkus to prosím později.', $html);
        self::assertStringNotContainsString('data-pairing-poll', $html);
    }

    public function testAMalformedPairingAnswerDegradesLikeAnOutage(): void
    {
        $app = $this->createApp(overrides: [ProgramProviderInterface::class => $this->failingStart(new ProgramDataException('kissj sent no pairing for v3/device/pairing/start'))]);

        $start = $this->request($app, 'POST', '/profil/propojit');

        self::assertSame(302, $start->getStatusCode());
        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Propojení se teď nedaří, zkus to prosím později.', $html);
        self::assertStringNotContainsString('tab-avatar', $html);
    }

    public function testProgramsLogOutOnAnExpiredSession(): void
    {
        $provider = new ThrowingProgramProvider(
            identityException: new SessionExpiredException(),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
        ]);
        $this->loginAs($app, 1, 'Jana Nováková');

        // kissj no longer knows the device session (its 401) → the reader is logged out on the next load
        $response = $this->request($app, 'GET', '/programy');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('tab-avatar', $html);
        self::assertStringContainsString('class="link-card is-highlight pair-card"', $html);
        // the same toast as Domů says why, and the worker keeps the page out of the offline copy
        self::assertStringContainsString('<p><strong>Odhlášeno</strong><span>Propojení s KissJ už neplatí, přihlas se znovu.</span></p>', $html);
        self::assertSame('1', $response->getHeaderLine('X-Toast'));
        self::assertStringNotContainsString('class="notice"', $html);
        // and Profil says it too (Authenticator::sessionGone())
        self::assertStringContainsString('Propojení s KissJ už neplatí, přihlas se znovu.', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    /**
     * The normal case when kissj is down is that BOTH calls fail in the same request.
     * The first one signs the participant out, and its explanation used to be
     * overwritten by the second one's notice — so the reader was logged out with no
     * reason given at all. The logout now speaks in the toast, the outage in the notice.
     */
    public function testTheLogoutExplanationSurvivesASecondFailureInTheSameRequest(): void
    {
        $provider = new ThrowingProgramProvider(
            programsException: new \App\Program\ProgramUnavailableException('down'),
            identityException: new SessionExpiredException(),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
        ]);
        $this->loginAs($app, 1, 'Jana Nováková');

        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringNotContainsString('tab-avatar', $html, 'the participant was not logged out');
        self::assertStringContainsString('<p><strong>Odhlášeno</strong><span>Propojení s KissJ už neplatí, přihlas se znovu.</span></p>', $html);
        self::assertStringContainsString('<p class="notice" data-key="notice">Programy se nepodařilo načíst, zkus to prosím později.</p>', $html);
    }

    /** obrok19's local source, with a start that fails as told. */
    private function failingStart(\Throwable $failure): ProgramProviderInterface
    {
        return new class (new LocalProgramProvider(dirname(__DIR__, 2) . '/events/obrok19/fixtures', $this->stubState), $failure) extends DelegatingProvider {
            public function __construct(ProgramProviderInterface $inner, private readonly \Throwable $failure)
            {
                parent::__construct($inner);
            }

            public function startPairing(string $deviceId): Pairing
            {
                throw $this->failure;
            }
        };
    }

    /**
     * A 200 carrying something that is not programme data is the same failure to the
     * reader as a connection that never arrived: a notice, not a 500.
     */
    public function testAMalformedPayloadDegradesLikeAnOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            programsException: new ProgramDataException('kissj returned a non-array payload'),
        );
        $app = $this->createApp(overrides: [ProgramProviderInterface::class => $provider]);

        $response = $this->request($app, 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'Programy se nepodařilo načíst, zkus to prosím později.',
            (string) $response->getBody(),
        );
    }
}
