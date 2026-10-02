<?php

declare(strict_types=1);

namespace Tests\Functional;

final class HandbookTest extends AppTestCase
{
    /** The handbook has no screen of its own — it is an entry on Odkazy pointing at the PDF. */
    public function testHandbookIsOfferedOnTheLinksScreen(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/odkazy');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Handbook (PDF)', $html);
        self::assertStringContainsString('href="/obrok19/handbook/download"', $html);
    }

    public function testHandbookPageIsGone(): void
    {
        self::assertSame(404, $this->request($this->createApp(), 'GET', '/handbook')->getStatusCode());
    }

    public function testDownloadHeaders(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/handbook/download');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Obrok19_handbook.pdf', $response->getHeaderLine('Content-Disposition'));
        self::assertGreaterThan(0, $response->getBody()->getSize());
    }
}
