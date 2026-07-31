<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\SkautisGatewayInterface;

final class Obrok27Test extends AppTestCase
{
    public function testObrok27Boots(): void
    {
        $response = $this->request($this->createApp('obrok27'), 'GET', '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Obrok 2027', (string) $response->getBody());
    }

    public function testEveryEnabledFeatureRenders(): void
    {
        $app = $this->createApp('obrok27', overrides: [
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);
        foreach (['/novinky', '/mapa', '/odkazy', '/programy', '/harmonogram', '/handbook'] as $uri) {
            self::assertSame(200, $this->request($app, 'GET', $uri)->getStatusCode(), $uri);
        }
    }

    public function testHandbookDownloadMissingFileIs404(): void
    {
        // PDF handbooku 2027 ještě není nahraný → stránka funguje, stažení 404
        self::assertSame(404, $this->request($this->createApp('obrok27'), 'GET', '/handbook/download')->getStatusCode());
    }
}
