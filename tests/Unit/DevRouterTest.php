<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ScriptVersion;
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
        $_SERVER['REQUEST_URI'] = '/sw.js';

        try {
            $result = (static fn(): mixed => require dirname(__DIR__, 2) . '/bin/router.php')();
        } finally {
            $_SERVER['REQUEST_URI'] = $saved;
        }

        self::assertFalse($result);
        self::assertStringContainsString("require __DIR__ . '/../www/index.php';", (string) file_get_contents(dirname(__DIR__, 2) . '/bin/router.php'));
    }

    public function testAVersionedScriptPathMapsToTheFile(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/script-path.php';
        $www = dirname(__DIR__, 2) . '/www';
        $file = (new ScriptVersion($www))->files()[0];

        self::assertSame($www . '/js/' . $file, eventapp_script_file('/js/0a1b2c3d/' . $file, $www));
        self::assertNull(eventapp_script_file('/js/0a1b2c3d/nope.js', $www));
        self::assertNull(eventapp_script_file('/js/' . $file, $www));               // no version segment: an ordinary file path
        self::assertNull(eventapp_script_file('/js/0a1b2c3d/../../.env', $www));
        self::assertNull(eventapp_script_file('/js/0A1B2C3D/' . $file, $www));      // 8 lower-case hex only
    }
}
