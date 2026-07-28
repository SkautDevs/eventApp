<?php

declare(strict_types=1);

namespace Tests\Functional;

final class HomepageTest extends AppTestCase
{
    public function testHomepageRenders(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Obrok 2019', $html);
        self::assertStringContainsString('Krizový telefon', $html);
    }
}
