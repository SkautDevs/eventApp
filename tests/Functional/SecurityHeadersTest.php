<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventCatalog;
use App\Kernel;
use Psr\Http\Message\ResponseInterface;

/** The four fixed headers and the policy, on an event page, the picker and both kinds of 404. */
final class SecurityHeadersTest extends AppTestCase
{
    private static function assertSecurityHeaders(ResponseInterface $response, string $where): void
    {
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), $where);
        self::assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'), $where);
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), $where);
        self::assertSame('geolocation=(), camera=(), microphone=(), payment=()', $response->getHeaderLine('Permissions-Policy'), $where);
        self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'), $where);
    }

    private function instance(): \Slim\App
    {
        return Kernel::createInstance(new EventCatalog(dirname(__DIR__) . '/fixtures/events'), new \DateTimeImmutable('2026-09-30'));
    }

    public function testAnEventPage(): void
    {
        self::assertSecurityHeaders($this->request($this->createApp(), 'GET', '/'), 'event page');
    }

    public function testAnEventsNotFound(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/neexistuje');

        self::assertSame(404, $response->getStatusCode());
        self::assertSecurityHeaders($response, 'event 404');
    }

    public function testThePicker(): void
    {
        self::assertSecurityHeaders($this->rawRequest($this->instance(), 'GET', '/'), 'picker');
    }

    public function testTheInstancesNotFound(): void
    {
        $response = $this->rawRequest($this->instance(), 'GET', '/nope/programy');

        self::assertSame(404, $response->getStatusCode());
        self::assertSecurityHeaders($response, 'instance 404');
    }
}
