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
        self::assertStringContainsString('google.com/maps/d/u/1/embed', (string) $response->getBody());
    }
}
