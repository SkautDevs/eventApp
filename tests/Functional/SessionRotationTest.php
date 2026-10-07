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
    private const int PORT = 9091;

    private const string DATABASE = 'var/session-rotation-test.sqlite';

    /** A throwaway token for this server only. */
    private const string ADMIN_TOKEN = 'session-rotation-admin';

    private static ?WebServerManager $server = null;

    private static bool $sessionsPreexisting = false;

    private Client $http;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$sessionsPreexisting = is_dir($root . '/var/sessions');
        $server = new WebServerManager($root . '/www', '127.0.0.1', self::PORT, $root . '/bin/router.php', '/', [
            'APP_DEBUG' => '0',
            'SENTRY_DSN' => '',
            'PROGRAM_PROVIDER_KORBO26' => 'stub',
            'PUSH_DB_PATH' => self::DATABASE,
            'ADMIN_TOKEN_KORBO26' => self::ADMIN_TOKEN,
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
        // the server's sessions go to var/sessions (Session's own save path)
        $sessions = dirname(__DIR__, 2) . '/var/sessions';
        if (!self::$sessionsPreexisting && is_dir($sessions)) {
            foreach (glob($sessions . '/sess_*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($sessions);
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
        $before = $this->anonymousSession();
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
        $loggedIn = $this->cookieOf($this->login($this->anonymousSession()));
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

    public function testACookielessReadStartsNoSession(): void
    {
        foreach (['/korbo26/', '/korbo26/programy', '/korbo26/profil', '/korbo26/novinky'] as $path) {
            self::assertSame([], $this->get($path)->getHeader('Set-Cookie'), $path . ' started a session for a reader who never wrote');
        }
    }

    /** Final review m1: a replayed bogus or long-gone cookie must not make PHP issue and store a fresh session. */
    public function testAReadUnderAnUnknownCookieStartsNoSession(): void
    {
        $sessions = dirname(__DIR__, 2) . '/var/sessions';
        $before = glob($sessions . '/sess_*') ?: [];
        foreach ([str_repeat('b', 32), 'not,a-stored-id', '../../etc'] as $cookie) {
            foreach (['/korbo26/', '/korbo26/profil'] as $path) {
                $response = $this->get($path, $cookie);
                self::assertSame(200, $response->getStatusCode());
                self::assertSame([], $response->getHeader('Set-Cookie'), $path . ' under ' . $cookie);
            }
        }
        self::assertSame($before, glob($sessions . '/sess_*') ?: [], 'no session file was created');
    }

    /** Review Focus 2 */
    public function testAFirstLoginWithoutAnyCookieLogsIn(): void
    {
        $response = $this->http->post('/korbo26/profil/tie', ['form_params' => ['tieCode' => 'KORBO1']]);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('TIE KORBO1', (string) $this->get('/korbo26/profil', $this->cookieOf($response))->getBody());
    }

    /** Review Focus 1 */
    public function testAWriteUnderAnUnknownCookieLandsInTheSessionTheBrowserGets(): void
    {
        $response = $this->http->post('/korbo26/profil/tie', [
            'headers' => ['Cookie' => 'eventapp=' . str_repeat('a', 32)],
            'form_params' => ['tieCode' => 'WRONG1'],
        ]);
        $issued = $this->cookieOf($response);
        self::assertNotSame(str_repeat('a', 32), $issued, 'strict mode never adopts an ID it did not issue');
        self::assertStringContainsString('Neplatný TIE kód.', (string) $this->get('/korbo26/profil', $issued)->getBody());
    }

    public function testALoggedInPageReissuesTheSameCookieForThirtyDays(): void
    {
        $id = $this->cookieOf($this->login($this->anonymousSession()));
        $page = $this->get('/korbo26/profil', $id);
        $line = implode("\n", $page->getHeader('Set-Cookie'));
        self::assertStringContainsString('eventapp=' . $id, $line, 'same ID, no rotation');
        self::assertMatchesRegularExpression('/Max-Age=(\d+)/', $line);
        preg_match('/Max-Age=(\d+)/', $line, $m);
        // the second boundary may fall between the two clocks that compute it
        self::assertEqualsWithDelta(2592000, (int) $m[1], 1);
        self::assertStringContainsString('HttpOnly', $line);
        self::assertStringContainsStringIgnoringCase('SameSite=Lax', $line);

        $fragment = $this->http->get('/korbo26/profil', ['headers' => ['Cookie' => 'eventapp=' . $id, 'X-Screen' => '1']]);
        self::assertSame([], $fragment->getHeader('Set-Cookie'), 'only a full page slides the cookie');
    }

    /** Review I2: read_and_close never updates the file, so the cookie's slide has to touch it */
    public function testALoggedInPageSlidesTheStoredSessionToo(): void
    {
        $id = $this->cookieOf($this->login($this->anonymousSession()));
        $file = dirname(__DIR__, 2) . '/var/sessions/sess_' . $id;
        self::assertFileExists($file);
        $backdated = time() - 2 * 86400;
        touch($file, $backdated);

        $this->get('/korbo26/profil', $id);
        clearstatcache(true, $file);
        self::assertGreaterThan($backdated + 86400, filemtime($file), 'the session file slides with the cookie');
        self::assertStringContainsString('TIE KORBO1', (string) $this->get('/korbo26/profil', $id)->getBody());
    }

    /** Review I3: the page states its own cache policy, whatever the cookie and the host's limiter */
    public function testEveryPageAndFragmentIsUncacheableWithOrWithoutASession(): void
    {
        $policy = ['no-store, no-cache, must-revalidate'];
        self::assertSame($policy, $this->get('/korbo26/')->getHeader('Cache-Control'), 'cookieless page');
        self::assertSame($policy, $this->get('/korbo26/novinky')->getHeader('Cache-Control'));

        $id = $this->cookieOf($this->login($this->anonymousSession()));
        self::assertSame($policy, $this->get('/korbo26/profil', $id)->getHeader('Cache-Control'), 'logged-in page');
        $fragment = $this->http->get('/korbo26/programy', ['headers' => ['Cookie' => 'eventapp=' . $id, 'X-Screen' => '1']]);
        self::assertSame($policy, $fragment->getHeader('Cache-Control'), 'logged-in fragment');
        // PHP's limiter is off: none of its headers come along
        self::assertSame([], $fragment->getHeader('Pragma'));
        self::assertSame([], $fragment->getHeader('Expires'));
    }

    public function testTheAdminPagesKeepTheirOwnPolicy(): void
    {
        $hop = $this->get('/korbo26/admin/notify?token=' . self::ADMIN_TOKEN);
        self::assertSame(303, $hop->getStatusCode());
        self::assertSame(['no-store'], $hop->getHeader('Cache-Control'));

        $page = $this->get('/korbo26/admin/notify', $this->cookieOf($hop));
        self::assertSame(200, $page->getStatusCode());
        self::assertSame(['no-store'], $page->getHeader('Cache-Control'));
    }

    public function testALoggedOutPageDoesNotSlideTheCookie(): void
    {
        self::assertSame([], $this->get('/korbo26/profil', $this->anonymousSession())->getHeader('Set-Cookie'));
    }

    /** A session that exists but is not logged in: a wrong code writes tieError, which starts one. */
    private function anonymousSession(): string
    {
        $response = $this->http->post('/korbo26/profil/tie', ['form_params' => ['tieCode' => 'WRONG1']]);
        self::assertSame(302, $response->getStatusCode());

        return $this->cookieOf($response);
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
