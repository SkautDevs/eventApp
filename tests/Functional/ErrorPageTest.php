<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventCatalog;
use App\Kernel;

/** Errors are pages of the event, in Czech, with a way back — and never a stack trace by accident. */
final class ErrorPageTest extends AppTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_DEBUG']);
        parent::tearDown();
    }

    private function appWithABrokenRoute(): \Slim\App
    {
        $app = $this->createApp();
        $app->get('/boom', function () {
            throw new \RuntimeException('kaboom in the handler');
        });

        return $app;
    }

    public function testANotFoundPageWearsTheEventAndPointsHome(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/tohle-tady-neni');
        $html = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Tohle tady není.', $html);
        self::assertStringContainsString('<a class="highlight" href="/obrok19/">Zpět na úvod</a>', $html);
        self::assertStringContainsString('<title>Nenalezeno · Obrok 2019</title>', $html);
        self::assertStringContainsString('--color-base: #2a9272', $html);
        self::assertStringContainsString('class="tabbar"', $html);
    }

    public function testAMethodNotAllowedReadsAsNotFound(): void
    {
        $response = $this->request($this->createApp(), 'POST', '/');

        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('Tohle tady není.', (string) $response->getBody());
    }

    public function testAServerErrorShowsNoDetailsWithoutDebug(): void
    {
        $response = $this->request($this->appWithABrokenRoute(), 'GET', '/boom');
        $html = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Něco se pokazilo. Zkus to za chvíli.', $html);
        self::assertStringContainsString('<title>Chyba · Obrok 2019</title>', $html);
        self::assertStringNotContainsString('kaboom', $html);
        self::assertStringNotContainsString('error-details', $html);
    }

    public function testAServerErrorShowsTheTraceUnderDebug(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        $html = (string) $this->request($this->appWithABrokenRoute(), 'GET', '/boom')->getBody();

        self::assertStringContainsString('<details class="error-details">', $html);
        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('kaboom in the handler', $html);
        self::assertStringContainsString('ErrorPageTest.php', $html);
    }

    /** app.js treats any non-200 as "not a screen" and navigates, so the reader sees a page. */
    public function testAFragmentRequestThatFailsGetsTheWholeDocument(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/tohle-tady-neni', null, ['X-Screen' => '1']);
        $html = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('<!DOCTYPE html>', trim($html));
        self::assertStringContainsString('Tohle tady není.', $html);
        self::assertSame('X-Screen', $response->getHeaderLine('Vary'));
    }

    public function testTheInstanceNotFoundPageIsPlain(): void
    {
        $app = Kernel::createInstance(new EventCatalog(dirname(__DIR__) . '/fixtures/events'), new \DateTimeImmutable('2026-09-30'));
        $response = $this->rawRequest($app, 'GET', '/neni-takova-akce/');
        $html = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Tohle tady není.', $html);
        self::assertStringContainsString('<a href="/">Zpět na úvod</a>', $html);
        self::assertStringNotContainsString('--color-', $html);
        self::assertStringNotContainsString('class="appbar"', $html);
    }

    public function testAJsonClientStillGetsJson(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/tohle-tady-neni', null, ['Accept' => 'application/json']);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testTheErrorAndOfflinePagesCarryNoFreshnessLine(): void
    {
        $app = $this->createApp();

        self::assertStringNotContainsString('data-freshness', (string) $this->request($app, 'GET', '/tohle-tady-neni')->getBody());
        self::assertStringNotContainsString('data-freshness', (string) $this->request($app, 'GET', '/offline')->getBody());
    }
}
