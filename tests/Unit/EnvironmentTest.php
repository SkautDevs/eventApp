<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testPhpVersionFloor(): void
    {
        self::assertGreaterThanOrEqual(80300, PHP_VERSION_ID);
    }

    /** The deploy numbers docs/deployment.md promises. */
    public function testTheDockerStackIsSizedAndNamesItsRelease(): void
    {
        $root = dirname(__DIR__, 2);
        $fpm = (string) file_get_contents($root . '/docker/php-fpm.conf');
        $nginx = (string) file_get_contents($root . '/docker/nginx.conf');
        $dockerfile = (string) file_get_contents($root . '/Dockerfile');
        $compose = (string) file_get_contents($root . '/docker-compose.prod.yml');
        $phpIni = (string) file_get_contents($root . '/docker/php.ini');
        $envExample = (string) file_get_contents($root . '/.env.example');

        foreach (['pm = dynamic', 'pm.max_children = 20', 'pm.start_servers = 4', 'pm.min_spare_servers = 2', 'pm.max_spare_servers = 6'] as $line) {
            self::assertStringContainsString($line, $fpm);
        }
        self::assertMatchesRegularExpression('~location = /index\.php \{[^}]*fastcgi_read_timeout 45s;~', $nginx);
        self::assertStringContainsString('ARG APP_RELEASE=dev', $dockerfile);
        self::assertStringContainsString('ENV APP_RELEASE=$APP_RELEASE', $dockerfile);
        self::assertStringContainsString('APP_RELEASE: "${APP_RELEASE:-dev}"', $compose);
        foreach (['display_errors = Off', 'log_errors = On', 'expose_php = Off', 'zend.exception_ignore_args = On'] as $line) {
            self::assertStringContainsString($line, $phpIni);
        }
        // an APP_RELEASE line copied into .env, even an empty one, would override the image's hash
        self::assertMatchesRegularExpression('/^# APP_RELEASE=$/m', $envExample);
        self::assertDoesNotMatchRegularExpression('/^APP_RELEASE=/m', $envExample);
        foreach (['SENTRY_DSN', 'SENTRY_TRACES_SAMPLE_RATE', 'SENTRY_PROFILES_SAMPLE_RATE', 'APP_RELEASE', 'TRUSTED_PROXY_COUNT', 'PUSH_ENDPOINT_HOSTS'] as $name) {
            self::assertStringContainsString($name . '=', $envExample);
            self::assertStringContainsString('`' . $name . '`', (string) file_get_contents($root . '/docs/deployment.md'));
        }
    }
}
