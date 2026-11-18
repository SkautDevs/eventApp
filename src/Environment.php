<?php

declare(strict_types=1);

namespace App;

/**
 * The process configuration, read once: a snapshot of $_ENV and of the process environment
 * (getenv()) taken by fromGlobals(), and the one place in src/ that reads either. Kernel
 * takes the snapshot when it builds an app, so a change after that is not seen by that app
 * (in production an app lives for one request).
 *
 * Env vars are per event where they are secrets or choices of an event — `<NAME>_<SLUG>`
 * with the slug upper-cased and `-` turned into `_` (`PROGRAM_PROVIDER_OBROK27`,
 * `KISSJ_API_KEY_OBROK27`, `ADMIN_TOKEN_OBROK27`), read through forEvent() (which falls
 * back from `$_ENV` to `getenv`, because php-fpm may leave `$_ENV` empty); `APP_DEBUG`,
 * `PROGRAM_CACHE_TTL`, `VAPID_*`, `PUSH_DB_PATH`, `SESSION_PATH` (sessionPath(), default
 * `var/sessions`, relative paths against the project root like `PUSH_DB_PATH`; the
 * browser tests point it at a temp dir), `STUB_STATE_PATH` (stubStatePath(), default
 * `var/stub`, resolved the same way; every test points it at a temp dir),
 * `PUSH_ENDPOINT_HOSTS`, `TRUSTED_PROXY_COUNT`, `TRUSTED_PROXIES`, `SENTRY_DSN`,
 * `SENTRY_TRACES_SAMPLE_RATE` (`.env.example`: 0.05), `SENTRY_PROFILES_SAMPLE_RATE` and
 * `APP_RELEASE` are instance-wide and read from `$_ENV` (`TRUSTED_PROXIES` falls back to
 * `getenv`). There is no instance-wide kissj URL.
 *
 * Each variable keeps the lookup it always had. A per-event variable (forEvent(),
 * `<NAME>_<SLUG>`) and TRUSTED_PROXIES fall back from $_ENV to the process environment,
 * because $_ENV can be empty under php-fpm's variables_order while the process environment
 * still holds the value; every other variable is read from $_ENV only. A key missing from
 * both snapshots is then read live with getenv($key): under Apache mod_php the variables
 * of SetEnv, PassEnv and SetEnvIf are visible only to getenv($key) (it asks the SAPI),
 * never to getenv() without an argument, so that per-key read must stay.
 */
final readonly class Environment
{
    /**
     * @param array<array-key, mixed> $env     $_ENV as it was when the snapshot was taken
     * @param array<array-key, mixed> $process getenv() as it was when the snapshot was taken
     */
    private function __construct(
        private array $env,
        private array $process,
    ) {}

    /** A snapshot of $_ENV and getenv(), taken once. */
    public static function fromGlobals(): self
    {
        return new self($_ENV, getenv());
    }

    /**
     * A variable from $_ENV only. An empty value is returned as it is; the default applies
     * only to a variable that is not set (or not a string).
     */
    public function get(string $name, string $default = ''): string
    {
        $value = $this->env[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * A per-event setting: `<NAME>_<SLUG>` from $_ENV, else from the process environment;
     * an empty or missing value is the default. Deliberately no fallback to the unsuffixed
     * name: one instance serves every event, so an instance-wide ADMIN_TOKEN or
     * KISSJ_API_KEY would silently apply to all of them.
     */
    public function forEvent(string $slug, string $name, string $default = ''): string
    {
        $key = self::eventKey($slug, $name);
        // the live getenv($key) is the SAPI's (mod_php's SetEnv); see the class docblock
        $value = $this->env[$key] ?? $this->process[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** The per-event variable name: `PROGRAM_PROVIDER` for `obrok27` → `PROGRAM_PROVIDER_OBROK27`. */
    public static function eventKey(string $slug, string $name): string
    {
        return $name . '_' . strtoupper(str_replace('-', '_', $slug));
    }

    /**
     * APP_DEBUG. An explicit whitelist rather than a cast: (bool) "false" and (bool) "off"
     * are both true, so an operator writing the obvious APP_DEBUG=false would turn debug
     * pages on.
     */
    public function debug(): bool
    {
        return in_array($this->env['APP_DEBUG'] ?? '', ['1', 'true', 'on'], true);
    }

    /**
     * PROGRAM_CACHE_TTL: seconds a kissj answer is served without asking again. Whole
     * seconds only; anything else — empty, negative, "5m" — is the default, 300. 0 asks
     * every time and still keeps the last answer for an outage.
     */
    public function programCacheTtl(): int
    {
        $value = $this->env['PROGRAM_CACHE_TTL'] ?? '';

        return is_string($value) && ctype_digit($value) ? (int) $value : 300;
    }

    /**
     * PUSH_DB_PATH: one SQLite file for every event, subscriptions and the message log;
     * var/push.sqlite unless set, a relative path resolved against the project root.
     */
    public function pushDbPath(string $root): string
    {
        $path = $this->get('PUSH_DB_PATH', 'var/push.sqlite');

        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }

    /**
     * SESSION_PATH: the session store's directory, var/sessions unless set; relative paths
     * are resolved against the project root like PUSH_DB_PATH. The browser tests point it
     * at a temporary directory of their own.
     */
    public function sessionPath(string $root): string
    {
        $path = $this->get('SESSION_PATH');
        if ($path === '') {
            return $root . '/var/sessions';
        }

        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }

    /**
     * STUB_STATE_PATH: where the `local` programme source keeps the registrations made in
     * the app and the revoked sessions, var/stub unless set; relative paths are resolved
     * against the project root like SESSION_PATH. The tests point it at temporary
     * directories of their own.
     */
    public function stubStatePath(string $root): string
    {
        $path = $this->get('STUB_STATE_PATH');
        if ($path === '') {
            return $root . '/var/stub';
        }

        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }

    /**
     * PROGRAM_PROVIDER_<SLUG>, required for every event: `local` (the event's fixture
     * files; null here) or the http(s) root URL of a kissj instance, returned without a
     * trailing slash. Anything else — a missing value included — stops the event from
     * booting: no event shows demo data by default. A URL also needs KISSJ_API_KEY_<SLUG>,
     * because kissj resolves the event from the key. The messages name the variables,
     * never their values.
     *
     * `programSource()` checks it at the top of `Kernel::create()`, before the container is
     * built, and a missing value or anything else (`stub` and `kissj` included) or a URL
     * without the key throws a `RuntimeException` naming the variable, so that event does
     * not boot — no event falls back to the fixtures, and every test pins `local`
     * (`tests/bootstrap.php`, for every directory under `events/` and
     * `tests/fixtures/events/`).
     *
     * @throws \RuntimeException naming the variable
     */
    public function programSource(string $slug): ?string
    {
        $value = $this->forEvent($slug, 'PROGRAM_PROVIDER');
        if ($value === 'local') {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (!in_array(is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new \RuntimeException(sprintf(
                '%s must be "local" or the http(s) root URL of a kissj instance',
                self::eventKey($slug, 'PROGRAM_PROVIDER'),
            ));
        }
        if ($this->forEvent($slug, 'KISSJ_API_KEY') === '') {
            throw new \RuntimeException(sprintf(
                '%s is a kissj URL and requires %s',
                self::eventKey($slug, 'PROGRAM_PROVIDER'),
                self::eventKey($slug, 'KISSJ_API_KEY'),
            ));
        }

        return rtrim($value, '/');
    }

    /**
     * VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY and VAPID_SUBJECT as set, '' where not; the
     * sender validates them.
     *
     * @return array{public: string, private: string, subject: string}
     */
    public function vapid(): array
    {
        return [
            'public' => $this->get('VAPID_PUBLIC_KEY'),
            'private' => $this->get('VAPID_PRIVATE_KEY'),
            'subject' => $this->get('VAPID_SUBJECT'),
        ];
    }

    /**
     * PUSH_ENDPOINT_HOSTS: the comma-separated push hosts allowed on top of the known
     * services (a test's or a dev setup's), trimmed, empty entries dropped.
     *
     * @return list<string>
     */
    public function pushEndpointHosts(): array
    {
        return self::commaList($this->get('PUSH_ENDPOINT_HOSTS'));
    }

    /** TRUSTED_PROXY_COUNT: whole hops; anything else is 0. */
    public function trustedProxyCount(): int
    {
        $value = $this->env['TRUSTED_PROXY_COUNT'] ?? '0';

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    /**
     * TRUSTED_PROXIES from $_ENV, else from the process environment: the comma-separated
     * addresses and CIDR ranges, trimmed, empty entries dropped. Http\ClientIp skips an
     * entry that is neither.
     *
     * @return list<string>
     */
    public function trustedProxies(): array
    {
        // the live getenv() is the SAPI's (mod_php's SetEnv); see the class docblock
        $raw = $this->env['TRUSTED_PROXIES'] ?? $this->process['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');

        return is_string($raw) ? self::commaList($raw) : [];
    }

    /** @return list<string> */
    private static function commaList(string $raw): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn(string $entry): bool => $entry !== '',
        ));
    }
}
