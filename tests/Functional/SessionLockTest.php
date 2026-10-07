<?php

declare(strict_types=1);

namespace Tests\Functional;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Panther\ProcessManager\WebServerManager;

/**
 * A slow provider call must not hold the reader's session: PHP's file handler locks a
 * session for as long as it is open, so a request that kept it open across a 10 s kissj
 * call made every other request with the same cookie wait behind it. Session reads with
 * read_and_close and reopens only to write, so the parallel request is answered at once.
 *
 * Real servers, because in-process apps run under CLI, where no session is ever started:
 * the app (several workers) in front of a kissj that takes two seconds over the list.
 */
final class SessionLockTest extends TestCase
{
    private const int PORT = 9092;

    private const int KISSJ_PORT = 9093;

    private const string DATABASE = 'var/session-lock-test.sqlite';

    private static ?WebServerManager $kissj = null;

    private static ?WebServerManager $server = null;

    private static bool $preexisting = false;

    private static bool $sessionsPreexisting = false;

    private static bool $cacheRootPreexisting = false;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$cacheRootPreexisting = is_dir($root . '/var/cache');
        self::$preexisting = is_dir($root . '/var/cache/korbo26');
        self::$sessionsPreexisting = is_dir($root . '/var/sessions');

        $kissj = new WebServerManager($root . '/tests/fixtures', '127.0.0.1', self::KISSJ_PORT, $root . '/tests/fixtures/slow-kissj.php', '/ready', []);
        $kissj->start();
        self::$kissj = $kissj;

        $server = new WebServerManager($root . '/www', '127.0.0.1', self::PORT, $root . '/bin/router.php', '/', [
            'APP_DEBUG' => '0',
            'SENTRY_DSN' => '',
            'PROGRAM_PROVIDER_KORBO26' => 'kissj',
            'KISSJ_BASE_URL' => 'http://127.0.0.1:' . self::KISSJ_PORT . '/',
            'KISSJ_API_KEY_KORBO26' => 'test-key',
            'PUSH_DB_PATH' => self::DATABASE,
            'PHP_CLI_SERVER_WORKERS' => '4',
            // tests/bootstrap.php's throwaway pair: every event has push and refuses to boot without one
            'VAPID_PUBLIC_KEY' => (string) ($_ENV['VAPID_PUBLIC_KEY'] ?? ''),
            'VAPID_PRIVATE_KEY' => (string) ($_ENV['VAPID_PRIVATE_KEY'] ?? ''),
            'VAPID_SUBJECT' => (string) ($_ENV['VAPID_SUBJECT'] ?? ''),
        ]);
        $server->start();
        self::$server = $server;
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->quit();
        self::$server = null;
        self::$kissj?->quit();
        self::$kissj = null;

        $root = dirname(__DIR__, 2);
        $file = $root . '/' . self::DATABASE;
        foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        // the servers' sessions go to var/sessions (Session's own save path)
        if (!self::$sessionsPreexisting && is_dir($root . '/var/sessions')) {
            self::removeTree($root . '/var/sessions');
        }

        $cache = $root . '/var/cache/korbo26';
        if (is_dir($cache)) {
            if (!self::$preexisting) {
                self::removeTree($cache);
            } else {
                // the slow stub's list is no developer's data either
                foreach (['list.json', 'kissj-down.json', 'kissj-reported.json'] as $name) {
                    if (is_file($cache . '/' . $name)) {
                        unlink($cache . '/' . $name);
                    }
                }
                foreach (glob($cache . '/*.lock') ?: [] as $path) {
                    unlink($path);
                }
            }
        }
        if (!self::$cacheRootPreexisting) {
            // rmdir refuses a non-empty directory, so another event's cache is never touched
            @rmdir($root . '/var/cache');
        }
    }

    public function testASlowProviderCallDoesNotHoldTheSessionForAParallelRequest(): void
    {
        $http = new Client(['base_uri' => 'http://127.0.0.1:' . self::PORT, 'allow_redirects' => false, 'http_errors' => false]);
        $failed = $http->post('/korbo26/profil/tie', ['form_params' => ['tieCode' => 'WRONG1']]);
        $cookie = ['Cookie' => 'eventapp=' . $this->cookieOf($failed)];
        // warm-up: the first request of a worker pays for the autoloader and the Twig compile,
        // which is not what this test measures
        $http->get('/korbo26/profil', ['headers' => $cookie]);

        $times = [];
        $slow = $http->getAsync('/korbo26/programy', [
            'headers' => $cookie,
            'on_stats' => function (TransferStats $s) use (&$times): void {
                $times['slow'] = $s->getTransferTime();
            },
        ]);
        // started after the slow one has opened (and, before this round, locked) the session
        $quick = $http->getAsync('/korbo26/profil', [
            'headers' => $cookie,
            'delay' => 300,
            'on_stats' => function (TransferStats $s) use (&$times): void {
                $times['quick'] = $s->getTransferTime();
            },
        ]);
        Utils::settle([$slow, $quick])->wait();

        self::assertGreaterThan(1.8, $times['slow'], 'the list call really was slow');
        self::assertLessThan(1.2, $times['quick'], 'the profile waited for the other request\'s session lock');
    }

    private function cookieOf(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            if (preg_match('/^eventapp=([^;]+)/', $line, $m) === 1) {
                return $m[1];
            }
        }
        self::fail('No session cookie in the response');
    }

    private static function removeTree(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
