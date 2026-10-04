<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventCatalog;
use App\Kernel;

final class HealthTest extends AppTestCase
{
    private string $dir;

    private mixed $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = $_ENV['PUSH_DB_PATH'] ?? null;
        $this->dir = sys_get_temp_dir() . '/health-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($_ENV['PUSH_DB_PATH']);
        } else {
            $_ENV['PUSH_DB_PATH'] = $this->previous;
        }
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function instance(): \Slim\App
    {
        return Kernel::createInstance(new EventCatalog(dirname(__DIR__) . '/fixtures/events'), new \DateTimeImmutable('2026-10-04'));
    }

    public function testAReachableDatabaseIsHealthy(): void
    {
        $_ENV['PUSH_DB_PATH'] = $this->dir . '/push.sqlite';

        $response = $this->rawRequest($this->instance(), 'GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        // a probe runs no migration: the file it touched has no schema
        $pdo = new \PDO('sqlite:' . $this->dir . '/push.sqlite');
        self::assertSame(0, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        self::assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll());
    }

    public function testAnUnreachableDatabaseIs503(): void
    {
        $_ENV['PUSH_DB_PATH'] = $this->dir . '/missing/push.sqlite';

        $response = $this->rawRequest($this->instance(), 'GET', '/health');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"ok":false}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testHealthIsServedByTheInstanceApp(): void
    {
        self::assertSame('', Kernel::boot('/health')->getBasePath());
    }
}
