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

    public function testLinksAreCardsInAList(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/odkazy')->getBody();

        self::assertStringContainsString('<ul class="link-list">', $html);
        // the handbook first, tonal
        self::assertMatchesRegularExpression('#<ul class="link-list">\s*<li>\s*<a class="link-card is-highlight" href="[^"]*handbook#', $html);
        // an external link carries the outward mark, hidden from the accessibility tree
        self::assertStringContainsString('href="https://obrok.skaut.cz/"', $html);
        self::assertStringContainsString('class="link-card-out fas fa-external-link-alt" aria-hidden="true"', $html);
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
