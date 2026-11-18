<?php

declare(strict_types=1);

namespace Tests\Browser;

use PHPUnit\Framework\Attributes\Group;

/**
 * The JavaScript unit tests under tests/js, run in Chrome: tests/Browser/router.php serves
 * them under /__tests__/, runner.html loads all.js, and the harness publishes the outcome
 * on window.__results. A module that fails to load is a named failure, not a time-out.
 */
#[Group('browser')]
final class JsUnitTest extends BrowserTestCase
{
    public function testEveryJavaScriptUnitTestPasses(): void
    {
        self::visit('/__tests__/runner.html');
        self::waitFor('return window.__results && window.__results.done === true;');
        $results = self::script('return window.__results;');
        self::assertIsArray($results);
        self::assertSame([], $results['failed'], (string) json_encode($results['failed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(0, $results['passed']);
    }

    public function testEveryTestFileIsImportedByTheRunner(): void
    {
        $all = (string) file_get_contents(dirname(__DIR__) . '/js/all.js');
        $files = glob(dirname(__DIR__) . '/js/*.test.js') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            self::assertStringContainsString("'./" . basename($file) . "'", $all);
        }
    }
}
