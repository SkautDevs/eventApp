<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\SkautisGatewayInterface;

/**
 * The korbo dev event: the Korbo data through the stub provider and the generated
 * fixtures, as a developer clicks through it. KorboProgramsTest covers the same data
 * through the kissj provider; this only checks that the event and its fixtures boot.
 */
final class KorboEventTest extends AppTestCase
{
    public function testTheProgramScreenServesAllKorboProgrammes(): void
    {
        $response = $this->request($this->createApp('korbo'), 'GET', '/programy');
        self::assertSame(200, $response->getStatusCode());

        preg_match_all('/data-pg-detail="(\d+)"/', (string) $response->getBody(), $details);
        self::assertCount(59, array_unique($details[1]));
    }

    public function testTheKorboTieCodeLogsIn(): void
    {
        $app = $this->createApp('korbo', [SkautisGatewayInterface::class => new FakeSkautisGateway()]);
        self::assertSame(302, $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'KORBO1'])->getStatusCode());

        $html = (string) $this->request($app, 'GET', '/programy')->getBody();
        self::assertStringContainsString('TIE KORBO1', $html);
        self::assertStringContainsString('is-registered', $html);
    }
}
