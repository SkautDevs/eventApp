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
        $obrok19 = $this->createApp();
        $minimal = $this->createApp('minimal', fixtureEvent: true);

        // minimal has only news → /novinky works
        self::assertSame(200, $this->request($minimal, 'GET', '/novinky')->getStatusCode());
        // minimal has only news → /mapa does not exist
        self::assertSame(404, $this->request($minimal, 'GET', '/mapa')->getStatusCode());
        // obrok19 has map → /mapa works
        self::assertSame(200, $this->request($obrok19, 'GET', '/mapa')->getStatusCode());
    }
}
