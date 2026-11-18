<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Http\ScriptVersion;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Panther\ProcessManager\WebServerManager;

/**
 * The dev server (`php -S` with bin/router.php, which the browser tests run too) serves a
 * versioned script path, /js/<8 hex>/<path>, as the file www/js/<path>. The built-in
 * server cannot be told to serve another file than the one asked for, so the router sends
 * it itself; this asks a real server, because under the CLI headers_list() is empty.
 */
final class ScriptServingTest extends TestCase
{
    private const int PORT = 9094;

    private const string DATABASE = 'var/script-serving-test.sqlite';

    private static ?WebServerManager $server = null;

    /** The server's STUB_STATE_PATH, so nothing it does writes var/stub. */
    private static string $stubState = '';

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$stubState = sys_get_temp_dir() . '/eventapp-script-serving-stub-' . bin2hex(random_bytes(6));
        mkdir(self::$stubState, 0o700);
        $server = new WebServerManager($root . '/www', '127.0.0.1', self::PORT, $root . '/bin/router.php', '/', [
            'APP_DEBUG' => '0',
            'SENTRY_DSN' => '',
            'PUSH_DB_PATH' => self::DATABASE,
            'STUB_STATE_PATH' => self::$stubState,
            // tests/bootstrap.php's, so the server creates no var/cache
            'CACHE_PATH' => (string) ($_ENV['CACHE_PATH'] ?? ''),
        ]);
        $server->start();
        self::$server = $server;
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->quit();
        self::$server = null;
        if (self::$stubState !== '' && is_dir(self::$stubState)) {
            array_map('unlink', glob(self::$stubState . '/*') ?: []);
            rmdir(self::$stubState);
        }
        $file = dirname(__DIR__, 2) . '/' . self::DATABASE;
        foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return array{int, array<string, string>, string} the status, the headers (lower-case names) and the body */
    private function get(string $path): array
    {
        $response = (new Client(['base_uri' => 'http://127.0.0.1:' . self::PORT, 'allow_redirects' => false, 'http_errors' => false]))->get($path);
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return [$response->getStatusCode(), $headers, (string) $response->getBody()];
    }

    public function testTheDevServerServesAVersionedScriptWithAScriptType(): void
    {
        $file = (new ScriptVersion(dirname(__DIR__, 2) . '/www'))->files()[0];
        [$status, $headers, $body] = $this->get('/js/0a1b2c3d/' . $file);
        self::assertSame(200, $status);
        self::assertSame('text/javascript; charset=utf-8', $headers['content-type']);
        self::assertStringEqualsFile(dirname(__DIR__, 2) . '/www/js/' . $file, $body);
    }

    /** The server keeps the scripts' version and graph in tests/bootstrap.php's CACHE_PATH, never in var/cache. */
    public function testAPageServedKeepsItsScriptCacheInTheTestsOwnDirectory(): void
    {
        $cache = (string) ($_ENV['CACHE_PATH'] ?? '');
        self::assertNotSame('', $cache);
        self::assertStringStartsNotWith(dirname(__DIR__, 2) . '/', $cache);

        [$status, , $body] = $this->get('/korbo26/profil');
        self::assertSame(200, $status);
        self::assertStringContainsString('rel="modulepreload"', $body);
        self::assertNotSame([], glob($cache . '/scripts-version-*.json') ?: []);
        self::assertNotSame([], glob($cache . '/scripts-graph-*.json') ?: []);
    }

    public function testAVersionedPathToNoFileIsTheAppsNotFound(): void
    {
        [$status] = $this->get('/js/0a1b2c3d/nope.js');
        self::assertSame(404, $status);
    }
}
