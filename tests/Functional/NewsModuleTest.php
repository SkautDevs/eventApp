<?php

declare(strict_types=1);

namespace Tests\Functional;

final class NewsModuleTest extends AppTestCase
{
    public function testNewsPageRendersItems(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/novinky');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hlavní program bude na stagi', (string) $response->getBody());
    }

    public function testNewsInMenu(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/')->getBody();

        self::assertStringContainsString('Novinky', $html);
    }

    public function testDisabledFeatureIs404(): void
    {
        $app = $this->createApp('minimal', fixtureEvent: true);

        // minimal has only news → homepage works, but /mapa does not exist
        self::assertSame(200, $this->request($app, 'GET', '/novinky')->getStatusCode());
        // Task 5 re-adds the enabled-event assertion for obrok19 /mapa when MapModule lands
        self::assertSame(404, $this->request($app, 'GET', '/mapa')->getStatusCode());
    }
}
