<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;

final class ServiceWorkerTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/www/sw.js');
    }

    public function testATapOpensTheMessagesUrlOnlyInsideTheWorkersScope(): void
    {
        $sw = $this->source();

        self::assertStringContainsString('notification.data', $sw);
        self::assertStringContainsString('url.href.startsWith(scope)', $sw);
    }

    public function testATapReusesAnOpenWindow(): void
    {
        $sw = $this->source();

        self::assertStringContainsString('clients.matchAll', $sw);
        self::assertStringContainsString('.navigate(', $sw);
    }
}
