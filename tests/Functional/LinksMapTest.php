<?php

declare(strict_types=1);

namespace Tests\Functional;

final class LinksMapTest extends AppTestCase
{
    public function testLinksPage(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/odkazy');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Krizový telefon', (string) $response->getBody());
    }

    public function testMapPage(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/mapa');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('google.com/maps/d/u/1/embed', $html);
        self::assertStringContainsString('<div class="map" data-map>', $html);
        self::assertStringContainsString('<p class="empty map-offline" data-map-offline hidden>Mapa potřebuje připojení.</p>', $html);
    }
}
