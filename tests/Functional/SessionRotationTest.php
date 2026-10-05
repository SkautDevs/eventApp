<?php

declare(strict_types=1);

namespace Tests\Functional;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Panther\ProcessManager\WebServerManager;

/**
 * The session ID rotates on login and logout, and a request that still carries the old ID
 * — one of the service worker's install or refill bursts, sent before the answer to the
 * login arrived — must neither log the reader out nor hand the browser a new cookie.
 *
 * In-process apps run under CLI, where no session is ever started (Session::regenerateId()
 * is a no-op there), so this test serves the app with PHP's built-in server and talks HTTP
 * to it: the cookies are real ones.
 */
final class SessionRotationTest extends TestCase
{
    private const PORT = 9091;

    private const DATABASE = 'var/session-rotation-test.sqlite';

    private static ?WebServerManager $server = null;

    private Client $http;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $server = new WebServerManager($root . '/www', '127.0.0.1', self::PORT, $root . '/bin/router.php', '/', [
            'APP_DEBUG' => '0',
            'SENTRY_DSN' => '',
            'PROGRAM_PROVIDER_KORBO26' => 'stub',
            'PUSH_DB_PATH' => self::DATABASE,
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
        $file = dirname(__DIR__, 2) . '/' . self::DATABASE;
        foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    protected function setUp(): void
    {
        $this->http = new Client([
            'base_uri' => 'http://127.0.0.1:' . self::PORT,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    public function testARequestWithTheIdBeforeTheLoginStaysLoggedOutAndSetsNoCookie(): void
    {
        $before = $this->cookieOf($this->get('/korbo26/profil'));
        $after = $this->cookieOf($this->login($before));
        self::assertNotSame($before, $after, 'the login rotates the session ID');

        $late = $this->get('/korbo26/profil', $before);
        self::assertSame(200, $late->getStatusCode());
        self::assertSame([], $late->getHeader('Set-Cookie'), 'a late request must not replace the logged-in cookie');
        self::assertStringNotContainsString('Odhlásit TIE', (string) $late->getBody());

        $current = $this->get('/korbo26/profil', $after);
        self::assertStringContainsString('TIE KORBO1', (string) $current->getBody());
    }

    public function testARequestWithTheIdBeforeTheLogoutIsLoggedOutAndSetsNoCookie(): void
    {
        $loggedIn = $this->cookieOf($this->login($this->cookieOf($this->get('/korbo26/profil'))));
        $logout = $this->http->post('/korbo26/profil/tie-logout', ['headers' => ['Cookie' => 'eventapp=' . $loggedIn]]);
        self::assertSame(302, $logout->getStatusCode());
        $loggedOut = $this->cookieOf($logout);
        self::assertNotSame($loggedIn, $loggedOut, 'the logout rotates the session ID');

        // the old ID was left holding the logged-out session, so whatever it answers the
        // reader is logged out, and the browser keeps the cookie the logout gave it
        $late = $this->get('/korbo26/profil', $loggedIn);
        self::assertSame([], $late->getHeader('Set-Cookie'));
        self::assertStringNotContainsString('TIE KORBO1', (string) $late->getBody());

        self::assertStringNotContainsString('TIE KORBO1', (string) $this->get('/korbo26/profil', $loggedOut)->getBody());
    }

    private function get(string $path, ?string $sessionId = null): ResponseInterface
    {
        return $this->http->get($path, $sessionId === null ? [] : ['headers' => ['Cookie' => 'eventapp=' . $sessionId]]);
    }

    private function login(string $sessionId): ResponseInterface
    {
        $response = $this->http->post('/korbo26/profil/tie', [
            'headers' => ['Cookie' => 'eventapp=' . $sessionId],
            'form_params' => ['tieCode' => 'KORBO1'],
        ]);
        self::assertSame(302, $response->getStatusCode());

        return $response;
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
}
