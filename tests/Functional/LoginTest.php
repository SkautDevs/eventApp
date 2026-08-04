<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\SkautisGatewayInterface;

final class LoginTest extends AppTestCase
{
    public function testSkautisPostbackLogsInAndRedirects(): void
    {
        $app = $this->createApp(overrides: [
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);

        $response = $this->request($app, 'POST', '/?ReturnUrl=/programy', ['skautIS_Token' => 'abc']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/programy', $response->getHeaderLine('Location'));
        self::assertSame('skautis', $_SESSION['identity']['type']);
        self::assertSame(123, $_SESSION['identity']['skautisUserId']);
    }

    public function testLogoutPostback(): void
    {
        $app = $this->createApp(overrides: [
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);
        $this->request($app, 'POST', '/', ['skautIS_Token' => 'abc']);

        $response = $this->request($app, 'POST', '/', ['skautIS_Logout' => '1']);

        self::assertSame(302, $response->getStatusCode());
        self::assertArrayNotHasKey('identity', $_SESSION);
    }
}
