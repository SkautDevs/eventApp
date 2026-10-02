<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventCatalog;
use App\Kernel;

final class PickerTest extends AppTestCase
{
    private function instance(string $today = '2026-09-30'): \Slim\App
    {
        return Kernel::createInstance(new EventCatalog(dirname(__DIR__) . '/fixtures/events'), new \DateTimeImmutable($today));
    }

    public function testThePickerListsUpcomingThenPastAndHidesUnlisted(): void
    {
        $html = (string) $this->rawRequest($this->instance(), 'GET', '/')->getBody();

        self::assertStringContainsString('href="/listed-future/"', $html);
        self::assertStringContainsString('href="/listed-past/"', $html);
        self::assertStringNotContainsString('href="/minimal/"', $html);
        self::assertLessThan(strpos($html, 'Proběhlé akce'), strpos($html, 'Future Event'));
    }

    public function testAnUnknownPathIsNotFound(): void
    {
        self::assertSame(404, $this->rawRequest($this->instance(), 'GET', '/nope/programy')->getStatusCode());
    }

    public function testBootPicksTheEventFromTheFirstSegment(): void
    {
        $app = Kernel::boot('/obrok27/programy?x=1');
        self::assertSame('/obrok27', $app->getBasePath());
        self::assertSame('', Kernel::boot('/')->getBasePath());
        self::assertSame('', Kernel::boot('/nope/')->getBasePath());
    }

    public function testTheInstanceAppCarriesTheSecurityHeaders(): void
    {
        $response = $this->rawRequest($this->instance(), 'GET', '/nope/programy');
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
    }

    public function testTodayIsTheLocalDayNotTheUtcOne(): void
    {
        // 23:30 UTC on the 20th is 01:30 on the 21st in Prague
        $today = Kernel::today(new \DateTimeImmutable('2026-09-20 23:30:00 UTC'));
        self::assertSame('2026-09-21', $today->format('Y-m-d'));

        $html = (string) $this->rawRequest(
            Kernel::createInstance(new EventCatalog(dirname(__DIR__) . '/fixtures/events'), $today),
            'GET',
            '/',
        )->getBody();
        self::assertStringContainsString('href="/listed-future/"', $html);
    }

    public static function awkwardPaths(): iterable
    {
        yield 'doubled slash' => ['//obrok27/'];
        yield 'upper case' => ['/OBROK27/'];
        yield 'percent-encoded' => ['/%6Fbrok27/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardPaths')]
    public function testBootHandlesAwkwardPathsWithoutAServerError(string $uri): void
    {
        $app = Kernel::boot($uri);
        self::assertContains($app->getBasePath(), ['', '/obrok27']);
        self::assertLessThan(500, $this->rawRequest($app, 'GET', $uri)->getStatusCode());
    }
}
