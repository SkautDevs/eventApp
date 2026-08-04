<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\Identity;
use App\Auth\SkautisGatewayInterface;
use App\Auth\UnknownParticipantException;
use App\Program\ProgramProviderInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

final class ProviderFailureTest extends AppTestCase
{
    public function testProgramsPageDegradesGracefullyOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            programsException: new ConnectException('down', new Request('GET', 'x')),
        );
        $app = $this->createApp(overrides: [ProgramProviderInterface::class => $provider]);

        $response = $this->request($app, 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'Programy se nepodařilo načíst, zkuste to prosím později.',
            (string) $response->getBody(),
        );
    }

    public function testProgramsDegradeUnpersonalizedOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            identityExceptionAfterFirstCall: new ConnectException('down', new Request('GET', 'x')),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);

        // the first call (TIE code login) goes through fine
        $login = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        // on the second call (during GET /programy) kissj no longer answers
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringContainsString('Osobní program se nepodařilo načíst.', $html);
        // the user stays logged in, just without a highlighted program
        self::assertStringContainsString('TIE ABC123', $html);
    }

    public function testTieLoginDegradesGracefullyOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            identityException: new ConnectException('down', new Request('GET', 'x')),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);

        $login = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        // the TIE error belongs to the login screen, which now lives at /profil
        $html = (string) $this->request($app, 'GET', '/profil')->getBody();
        self::assertStringContainsString('Přihlášení se teď nedaří', $html);
    }

    public function testProgramsLogOutOnUnknownParticipantAfterInitialLogin(): void
    {
        $provider = new ThrowingProgramProvider(
            identityExceptionAfterFirstCall: new UnknownParticipantException('Unknown TIE code: ABC123'),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);

        // the first call (TIE code login) goes through fine
        $login = $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        // the participant vanished in the meantime (kissj returns 404) → the user is logged out on the next load
        $response = $this->request($app, 'GET', '/programy');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Váš TIE kód už není platný, byli jste odhlášeni.', $html);
        self::assertStringNotContainsString('TIE ABC123', $html);
        self::assertStringContainsString('Přihlaste se', $html);
    }
}

/** Test program provider that can fail the second and later getProgramsForIdentity() calls. */
final class ThrowingProgramProvider implements ProgramProviderInterface
{
    private int $identityCalls = 0;

    public function __construct(
        private readonly ?\Throwable $programsException = null,
        private readonly ?\Throwable $identityExceptionAfterFirstCall = null,
        private readonly ?\Throwable $identityException = null,
    ) {
    }

    public function getPrograms(): array
    {
        if ($this->programsException !== null) {
            throw $this->programsException;
        }

        return [];
    }

    public function getProgramsForIdentity(Identity $identity): array
    {
        $this->identityCalls++;

        if ($this->identityException !== null) {
            throw $this->identityException;
        }

        if ($this->identityCalls > 1 && $this->identityExceptionAfterFirstCall !== null) {
            throw $this->identityExceptionAfterFirstCall;
        }

        return [];
    }
}
