<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\SkautisGatewayInterface;

final class HarmonogramTest extends AppTestCase
{
    public function testAnonymousSeesScheduleAndLoginLink(): void
    {
        $app = $this->createApp(overrides: [
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);

        $response = $this->request($app, 'GET', '/harmonogram');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Středa', $html);
        self::assertStringContainsString('Zahajovací ceremoniál', $html);
        self::assertStringContainsString('Přihlaste se přes SkautIs', $html);
    }

    public function testLoggedUserSeesRegisteredProgram(): void
    {
        $app = $this->createApp(overrides: [
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);
        // FakeSkautisGateway logs in the user skautis:123,
        // registered.json gives them program id 14 (Služba v kuchyni, section 1, 15:00)
        $this->request($app, 'POST', '/', ['skautIS_Token' => 'abc']);

        $html = (string) $this->request($app, 'GET', '/harmonogram')->getBody();

        self::assertStringContainsString('Jan Novák (jnovak)', $html);
        self::assertStringContainsString('Váš program', $html);
        self::assertStringContainsString('Služba v kuchyni', $html);
    }
}
