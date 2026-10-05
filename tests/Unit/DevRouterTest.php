<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * bin/router.php is what `php -S` runs for every request. Returning false is how a router
 * tells the built-in server "serve this file yourself"; anything else goes to the app.
 */
final class DevRouterTest extends TestCase
{
    public function testAnExistingFileIsLeftToTheBuiltInServer(): void
    {
        $saved = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/app.js?v=12345678';

        try {
            $result = (static fn (): mixed => require dirname(__DIR__, 2) . '/bin/router.php')();
        } finally {
            $_SERVER['REQUEST_URI'] = $saved;
        }

        self::assertFalse($result);
        self::assertStringContainsString("require __DIR__ . '/../www/index.php';", (string) file_get_contents(dirname(__DIR__, 2) . '/bin/router.php'));
    }
}
