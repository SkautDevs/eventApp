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

    public function testHarmonogramDegradesUnpersonalizedOnProviderOutage(): void
    {
        $provider = new ThrowingProgramProvider(
            identityExceptionAfterFirstCall: new ConnectException('down', new Request('GET', 'x')),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);

        // první volání (přihlášení TIE kódem) proběhne v pořádku
        $login = $this->request($app, 'POST', '/harmonogram/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        // druhé volání (na GET /harmonogram) už kissj neodpoví
        $html = (string) $this->request($app, 'GET', '/harmonogram')->getBody();

        self::assertStringContainsString('Osobní program se nepodařilo načíst.', $html);
        // uživatel zůstává přihlášen, jen bez zvýrazněného programu
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

        $login = $this->request($app, 'POST', '/harmonogram/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        $html = (string) $this->request($app, 'GET', '/harmonogram')->getBody();
        self::assertStringContainsString('Přihlášení se teď nedaří', $html);
    }

    public function testHarmonogramLogsOutOnUnknownParticipantAfterInitialLogin(): void
    {
        $provider = new ThrowingProgramProvider(
            identityExceptionAfterFirstCall: new UnknownParticipantException('Neznámý TIE kód: ABC123'),
        );
        $app = $this->createApp(overrides: [
            ProgramProviderInterface::class => $provider,
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);

        // první volání (přihlášení TIE kódem) proběhne v pořádku
        $login = $this->request($app, 'POST', '/harmonogram/tie', ['tieCode' => 'ABC123']);
        self::assertSame(302, $login->getStatusCode());

        // účastník mezitím zmizel (kissj vrací 404) → uživatel je při dalším načtení odhlášen
        $response = $this->request($app, 'GET', '/harmonogram');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Váš TIE kód už není platný, byli jste odhlášeni.', $html);
        self::assertStringNotContainsString('TIE ABC123', $html);
        self::assertStringContainsString('Přihlaste se přes SkautIs', $html);
    }
}

/** Testovací poskytovatel programů, který umí druhé a další volání getProgramsForIdentity() shodit chybou. */
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
