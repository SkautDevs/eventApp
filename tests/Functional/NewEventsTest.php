<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventCatalog;
use App\Kernel;

final class NewEventsTest extends AppTestCase
{
    public function testNavigamusServesItsProgrammesOnFriday(): void
    {
        $html = (string) $this->request($this->createApp('navigamus25'), 'GET', '/programy')->getBody();

        self::assertStringContainsString('Plachtní regata', $html);
        self::assertStringContainsString('Plavba k místu prvního tábora skautek', $html);
        self::assertStringContainsString('page-20250606', $html);
        self::assertStringContainsString('nejsou zahrnuty v registračním poplatku', $html);
        self::assertStringNotContainsString('mapy.cz', $html);
    }

    public function testNavigamusTieParticipantLogsIn(): void
    {
        $app = $this->createApp('navigamus25');
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'NAVIGAMUS1']);

        self::assertStringContainsString('pro <strong>NAVIGAMUS1</strong>', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    public function testMiquikServesItsLectureGrid(): void
    {
        $app = $this->createApp('miquik26');
        $html = (string) $this->request($app, 'GET', '/programy')->getBody();

        self::assertStringContainsString('Smrt NEporazíme, ale jak přemůžeme strach?', $html);
        self::assertStringContainsString('Kotěr (105)', $html);
        self::assertStringNotContainsString('Program zatím není k dispozici.', $html);
    }

    public function testMiquikLogsInItsFixtureParticipant(): void
    {
        $app = $this->createApp('miquik26');
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'MIQUIK1']);

        self::assertStringContainsString('pro <strong>MIQUIK1</strong>', (string) $this->request($app, 'GET', '/profil')->getBody());
    }

    public function testMiquikHomepageRendersNoEmptyImageSource(): void
    {
        $html = (string) $this->request($this->createApp('miquik26'), 'GET', '/')->getBody();

        self::assertStringNotContainsString('src=""', $html);
        self::assertStringContainsString('Miquik 2026', $html);
    }

    public function testThePickerFilesNavigamusAsPastAndHidesMiquik(): void
    {
        $app = Kernel::createInstance(new EventCatalog(dirname(__DIR__, 2) . '/events'), new \DateTimeImmutable('2026-09-30'));
        $html = (string) $this->rawRequest($app, 'GET', '/')->getBody();

        self::assertStringContainsString('href="/navigamus25/"', $html);
        self::assertStringContainsString('href="/korbo26/"', $html);
        self::assertStringContainsString('href="/obrok27/"', $html);
        self::assertStringNotContainsString('miquik26', $html);
        self::assertStringNotContainsString('Miquik', $html);

        $upcoming = strpos($html, 'Nadcházející a probíhající akce');
        $past = strpos($html, 'Proběhlé akce');
        self::assertIsInt($upcoming);
        self::assertIsInt($past);
        self::assertGreaterThan($upcoming, strpos($html, 'href="/obrok27/"'));
        self::assertLessThan($past, strpos($html, 'href="/obrok27/"'));
        self::assertGreaterThan($past, strpos($html, 'href="/korbo26/"'));
        self::assertGreaterThan($past, strpos($html, 'href="/navigamus25/"'));
    }
}
