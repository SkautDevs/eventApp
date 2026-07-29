<?php

declare(strict_types=1);

namespace Tests\Functional;

final class ProgramsTest extends AppTestCase
{
    public function testProgramsPageGroupsBySections(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/programy');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Putování', $html);
        self::assertStringContainsString('Ukázková vycházka', $html);
        self::assertStringNotContainsString('map-vzlet.png', $html); // sekce Vzlet nemá program → nevykreslí se
        self::assertStringNotContainsString('Osobní volno', $html);
        // sekce bez programů se nevykreslují
        self::assertStringNotContainsString('EXPO', $html);
    }
}
