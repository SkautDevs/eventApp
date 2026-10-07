<?php

declare(strict_types=1);

namespace App;

use App\Telemetry\Collector;

/**
 * The reader's session, kept by PHP's file handler but never held open.
 *
 * Read: the stored values are loaded with read_and_close, so a slow request (kissj takes up
 * to 10 s) never makes the next request with the same cookie wait for the handler's lock —
 * one phone's install burst used to pin a dozen workers that way. A request without the
 * cookie reads nothing and starts nothing, so a crawler leaves no file behind.
 * Write: every change reopens the session, applies itself to freshly read values under the
 * handler's lock, and closes it again (transact()), which also makes a read-modify-write
 * atomic against a parallel request.
 *
 * Nothing depends on the host's ini: the store, the 30-day lifetime, GC, strict mode, the
 * cache limiter and the cookie flags are set here before every start. The store is chosen
 * as a pair (storage()): the files handler in var/sessions, else the files handler in a
 * private directory under the system temp dir, else the host's own handler *and* path,
 * untouched — the files handler is never pointed at a path meant for another handler
 * (tcp://… of a redis host). One cookie serves every event on the host, so each event's
 * values live under its own key.
 *
 * Under CLI (every in-process test) no session is ever started and $_SESSION is a plain array.
 */
final class Session
{
    /** Thirty days: longer than any camp; the cookie slides on every logged-in page (refreshCookie()). */
    public const int LIFETIME = 30 * 24 * 3600;

    private const string NAME = 'eventapp';

    /** refreshCookie() slides the session file too, but only once it is this old. */
    private const int TOUCH_AFTER = 24 * 3600;

    /**
     * Each session problem is reported once per process, not once per request.
     *
     * @var array<string, true>
     */
    private static array $reported = [];

    private readonly bool $http;

    private bool $loaded = false;

    /** The files handler's directory once chosen; false for the host's own store. */
    private string|false|null $storage = null;

    /**
     * The ID the browser holds and the store has: what a reopen resumes without sending the
     * cookie again. Null while the browser holds none we know to be stored.
     */
    private ?string $stored = null;

    public function __construct(
        private readonly string $namespace = '',
        private readonly ?string $savePath = null,
        ?bool $http = null,
        // names the temp-dir fallback store; without it there is none (storage())
        private readonly ?string $appRoot = null,
    ) {
        $this->http = $http ?? \PHP_SAPI !== 'cli';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->view()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->view()[$key]);
    }

    public function set(string $key, mixed $value): void
    {
        $this->transact(static function (array &$bag) use ($key, $value): void {
            $bag[$key] = $value;
        });
    }

    public function delete(string $key): void
    {
        // nothing to delete is no reason to open, or to create, a session
        if (!array_key_exists($key, $this->view())) {
            return;
        }
        $this->transact(static function (array &$bag) use ($key): void {
            unset($bag[$key]);
        });
    }

    /**
     * Runs $change on this event's values inside one open session and returns what it
     * returns. The handler's lock is held for exactly this long, so a check-and-consume
     * (the admin form's one-time nonce) cannot succeed twice in parallel requests.
     *
     * @param \Closure(array<string, mixed>&): mixed $change
     */
    public function transact(\Closure $change): mixed
    {
        $this->open(false);
        try {
            if ($this->namespace === '') {
                return $change($_SESSION);
            }
            $_SESSION[$this->namespace] ??= [];

            return $change($_SESSION[$this->namespace]);
        } finally {
            $this->close();
        }
    }

    /**
     * Issues a fresh session ID, carrying the data over, and leaves the old session behind
     * holding the data as it is at this moment. Called on every privilege change, *before*
     * the privilege is granted and *after* it is revoked, so the old ID only ever holds the
     * less privileged state. The old session is not destroyed: under strict mode a late
     * request with its ID — the service worker's install and refill bursts — would get a new
     * empty session and a Set-Cookie replacing the one the login just set.
     * A no-op under CLI.
     */
    public function regenerateId(): void
    {
        if (!$this->http) {
            return;
        }
        // opened with cookies on: the new ID has to reach the browser
        $this->open(true);
        try {
            session_regenerate_id(false);
            $this->stored = session_id();
        } finally {
            $this->close();
        }
    }

    /**
     * Sends the cookie again with a fresh 30-day expiry, same ID. Kernel calls it on every
     * full page a logged-in reader gets, so a participant who logged in at home weeks before
     * the camp is still logged in at the camp. No-op when no stored ID is known.
     *
     * The store slides with it: reads use read_and_close, which never updates the file, so
     * without this the GC would delete a session 30 days after its last *write* whatever the
     * cookie says. Touched at most once a day, so an install burst costs one touch.
     * On the host's own store (storage() fell through) only the cookie slides.
     */
    public function refreshCookie(): void
    {
        if (!$this->http || $this->stored === null || headers_sent()) {
            return;
        }
        setcookie(self::NAME, $this->stored, self::cookieOptions() + ['expires' => time() + self::LIFETIME]);
        if (is_string($this->storage)) {
            self::slide($this->storage . '/sess_' . $this->stored, time());
        }
    }

    /**
     * Picks the session store: $preferred when it is (or can be made, 0700) a writable
     * directory that is not a symlink; else the private temp-dir store of $appRoot
     * (fallbackPath()) under the same rules plus privacy; else null — the host's handler and
     * path, both left alone.
     *
     * The temp-dir name is predictable and the temp dir may be shared with other accounts,
     * and the files handler names each file after its session ID, so a directory someone
     * else could list is a session-hijack path. An existing fallback is therefore accepted
     * only if it is no symlink, belongs to this process's user and grants nothing to group or
     * others. var/sessions lives inside the app tree, which only the app's own account
     * writes, so it is only refused as a symlink; a $preferred outside the tree (SESSION_PATH
     * may name any directory) must also be private, or the next store is tried.
     */
    public static function storage(string $preferred, ?string $appRoot, ?string $tempDir = null): ?string
    {
        if (self::usable($preferred) && (self::inside($preferred, $appRoot) || self::isPrivate($preferred))) {
            return $preferred;
        }
        if ($appRoot === null) {
            return null;
        }
        $fallback = self::fallbackPath($appRoot, $tempDir);

        return self::usable($fallback) && self::isPrivate($fallback) ? $fallback : null;
    }

    /** The fallback store: one per checkout, so two apps on one host never share one. */
    public static function fallbackPath(string $appRoot, ?string $tempDir = null): string
    {
        return rtrim($tempDir ?? sys_get_temp_dir(), '/') . '/eventapp-sessions-' . substr(hash('sha256', $appRoot), 0, 8);
    }

    private static function usable(string $path): bool
    {
        if (is_link($path)) {
            return false;
        }
        if (!is_dir($path)) {
            @mkdir($path, 0o700, true);
        }
        clearstatcache(true, $path);

        return !is_link($path) && is_dir($path) && is_writable($path);
    }

    /** Whether $path, resolved, is $appRoot or below it; never without an app root. */
    private static function inside(string $path, ?string $appRoot): bool
    {
        $root = $appRoot === null ? false : realpath($appRoot);
        $real = realpath($path);

        return $root !== false && $real !== false && str_starts_with($real . '/', rtrim($root, '/') . '/');
    }

    private static function isPrivate(string $path): bool
    {
        // the process's own user; getmyuid() names the script file's owner, the same on a
        // suexec host, and the only answer where ext-posix is missing
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $perms = @fileperms($path);

        return @fileowner($path) === $uid && $perms !== false && ($perms & 0o077) === 0;
    }

    /** Moves the file's mtime to $now when it is older than a day; false when nothing was touched. */
    public static function slide(string $file, int $now): bool
    {
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        if ($mtime === false || $now - $mtime < self::TOUCH_AFTER) {
            return false;
        }

        return @touch($file, $now);
    }

    /** @return array<string, mixed> this event's values; reading never starts a session the browser has no cookie for */
    private function view(): array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $cookie = self::cookie();
            if ($this->http && $cookie !== null && session_status() !== \PHP_SESSION_ACTIVE) {
                $this->configure();
                if (!$this->mayBeStored($cookie)) {
                    // Strict mode would answer an ID it does not know with a fresh, empty
                    // session: a new file and a Set-Cookie for every replayed bogus or
                    // long-gone cookie. There is nothing to read, so nothing is started; a
                    // later write (open()) still gets a fresh ID the browser is sent.
                } elseif (@session_start(['read_and_close' => true])) {
                    // strict mode replaces an ID it does not know; that one is not stored yet
                    $this->stored = session_id() === $cookie ? $cookie : null;
                } else {
                    self::report('read', 'The session could not be read');
                }
            }
        }
        $all = $_SESSION ?? [];

        return $this->namespace === '' ? $all : ($all[$this->namespace] ?? []);
    }

    /**
     * False only when the files store is our own and plainly holds no session of this ID —
     * or the ID could never name one. On the host's handler (storage() fell through) there is
     * no way to tell, so PHP is asked as before.
     */
    private function mayBeStored(string $id): bool
    {
        if (!is_string($this->storage)) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9,-]{1,256}$/', $id) === 1 && is_file($this->storage . '/sess_' . $id);
    }

    private function open(bool $withCookie): void
    {
        $this->view();
        if (!$this->http) {
            $_SESSION ??= [];

            return;
        }
        $this->configure();
        if ($this->stored !== null) {
            session_id($this->stored);
        }
        // the browser already holds a stored ID: resuming it must not send its cookie again
        if (!@session_start($this->stored !== null && !$withCookie ? ['use_cookies' => 0] : [])) {
            // the change goes to a plain $_SESSION and is lost; the reader sees it as a
            // failed login rather than an error page
            self::report('open', 'The session could not be opened for writing');
            $_SESSION ??= [];
            ini_set('session.use_cookies', '1');
        }
    }

    private function close(): void
    {
        if (!$this->http || session_status() !== \PHP_SESSION_ACTIVE) {
            return;
        }
        // a plain start sent the cookie for whatever ID it settled on, and the write stores it
        $this->stored ??= session_id();
        session_write_close();
        ini_set('session.use_cookies', '1');
    }

    private function configure(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            return;
        }
        if ($this->savePath !== null) {
            $this->storage ??= $this->chooseStorage($this->savePath);
            // handler and path as a pair: the host may name another handler (redis,
            // memcached) whose save_path means nothing to the files handler
            if (is_string($this->storage)) {
                ini_set('session.save_handler', 'files');
                ini_set('session.save_path', $this->storage);
            }
        }
        session_name(self::NAME);
        // the page states its own cache policy (Kernel's screen middleware): PHP's limiter
        // would make it depend on the cookie and on the host's ini
        ini_set('session.cache_limiter', '');
        ini_set('session.lazy_write', '1');
        ini_set('session.serialize_handler', 'php');
        // without strict mode PHP adopts any ID the client presents: half of a fixation attack
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        // a private save path is outside any distribution cleanup cron, so PHP's own GC runs
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '1000');
        session_set_cookie_params(self::cookieOptions() + ['lifetime' => self::LIFETIME]);
    }

    private function chooseStorage(string $preferred): string|false
    {
        $chosen = self::storage($preferred, $this->appRoot);
        if ($chosen !== $preferred) {
            self::report('storage', $chosen === null
                ? 'No usable private session directory; using the host\'s session handler and path'
                : 'Session directory is not writable or not private; using the system temp dir');
        }

        return $chosen ?? false;
    }

    private static function report(string $what, string $message): void
    {
        if (isset(self::$reported[$what])) {
            return;
        }
        self::$reported[$what] = true;
        Collector::collect(new \RuntimeException($message));
    }

    /** @return array{path: string, domain: string, secure: bool, httponly: bool, samesite: string} */
    private static function cookieOptions(): array
    {
        return [
            'path' => '/',
            // host-only, whatever the host's session.cookie_domain says
            'domain' => '',
            // read off the request: required on HTTPS, and would break plain-http dev if hardcoded
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function cookie(): ?string
    {
        $value = $_COOKIE[self::NAME] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
