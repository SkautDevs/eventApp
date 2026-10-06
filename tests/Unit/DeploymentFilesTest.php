<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The Docker stack's files say what docs/deployment.md promises. */
final class DeploymentFilesTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testTheAdminTokenNeverReachesTheAccessLog(): void
    {
        $nginx = self::read('docker/nginx.conf');
        self::assertStringContainsString('map $request_uri $loggable_uri {', $nginx);
        // any case, any position, and any %-escape: %61dmin and admin%2F reach the admin route too
        self::assertStringContainsString('"~*^(?<path>[^?]*(?:admin|%[0-9a-f]{2})[^?]*)\?" $path;', $nginx);
        self::assertStringContainsString('access_log /var/log/nginx/access.log eventapp;', $nginx);
        self::assertStringNotContainsString('$request ', $nginx, 'the log line must not carry $request, which holds the query');
    }

    public function testTheProductionStackListensOnLoopbackAndComesBack(): void
    {
        $compose = self::read('docker-compose.prod.yml');
        self::assertStringContainsString('- 127.0.0.1:8080:80', $compose);
        self::assertSame(2, substr_count($compose, 'restart: unless-stopped'));
        self::assertStringContainsString('http://127.0.0.1/health', $compose);
        self::assertSame(2, substr_count($compose, 'max-size: 20m'));
    }

    public function testTracingSamplesFivePercent(): void
    {
        self::assertStringContainsString("\nSENTRY_TRACES_SAMPLE_RATE=0.05\n", self::read('.env.example'));
    }
}
