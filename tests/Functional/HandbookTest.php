<?php

declare(strict_types=1);

namespace Tests\Functional;

final class HandbookTest extends AppTestCase
{
    public function testHandbookPage(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/handbook');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Stáhnout PDF', (string) $response->getBody());
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
