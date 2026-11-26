<?php

declare(strict_types=1);

namespace Tools\Gate;

/**
 * The HTML gate: every event's pages as HTTP responses under a frozen clock, one file per
 * response, so a reference tree and a candidate tree can be diffed (Comparison). Each event
 * runs one fixed sequence of requests, logged out, as an admin and paired; the gate checks
 * that the sequence did what it says (a login that silently failed would otherwise be a
 * stable dump of the wrong thing) and aborts naming the step when it did not.
 */
final class HtmlGate
{
    /** The clocks every shipped event is dumped at, Prague time. */
    public const array INSTANTS = [
        '2026-09-17 10:00:00',
        '2027-03-01 12:00:00',
        '2027-04-10 12:00:00',
        '2027-05-20 10:00:00',
        '2027-06-04 15:00:00',
    ];

    /** The one clock the fixture events are dumped at. */
    public const string FIXTURE_INSTANT = '2027-05-20 10:00:00';

    /** The value of ADMIN_TOKEN_<SLUG> the server sets for every event. */
    private const string ADMIN_TOKEN = 'gate';

    private const string ELSEWHERE = 'https://elsewhere.example';

    /** Step 3's pages (step 6 repeats them): name => path under the event. */
    private const array PAGES = [
        'home' => '/',
        'profil' => '/profil',
        'programy' => '/programy',
        'mapa' => '/mapa',
        'informace' => '/informace',
        'offline' => '/offline',
        'precache' => '/precache.json',
        'handbook' => '/handbook/download',
        'not-found' => '/nic-takoveho',
    ];

    /** The pages of PAGES that are screens, fetched a second time with X-Screen: 1. */
    private const array SCREENS = ['home', 'profil', 'programy', 'mapa', 'informace', 'offline'];

    /** Response headers left out of a dump: they change per run or say nothing about the app. */
    private const array SKIPPED_HEADERS = ['date', 'host', 'connection', 'x-powered-by'];

    private string $baseUrl = '';

    private string $slug = '';

    /** The clock of the sequence under way, for error messages; null outside dump(). */
    private ?string $instant = null;

    /** Where the current sequence's files go; null writes nothing. */
    private ?string $dir = null;

    /** @var array<string, array<string, string>> jar name => cookie name => value */
    private array $jars = [];

    /** @param string|null $root the project root; the events come from events/ and tests/fixtures/events/ under it */
    public function __construct(private readonly ?string $root = null) {}

    private function root(): string
    {
        return $this->root ?? dirname(__DIR__, 2);
    }

    /** Dumps every sequence under $out (which it replaces) and returns the number of files written. */
    public function dump(string $out): int
    {
        Server::remove($out);
        $eventsDir = $this->eventsDir();
        $shipped = array_map('basename', glob($this->root() . '/events/*', GLOB_ONLYDIR) ?: []);
        $all = array_map('basename', glob($eventsDir . '/*') ?: []);
        sort($shipped, SORT_STRING);
        sort($all, SORT_STRING);
        $count = 0;
        try {
            foreach (self::INSTANTS as $instant) {
                $server = new Server();
                $this->baseUrl = $server->start($instant, $eventsDir);
                $this->instant = $instant;
                try {
                    foreach ($instant === self::FIXTURE_INSTANT ? $all : $shipped as $slug) {
                        fwrite(STDERR, sprintf("%s %s\n", $instant, $slug));
                        $count += $this->sequence($out . '/' . $instant . '/' . $slug, $slug);
                    }
                    $count += $this->instance($out . '/' . $instant . '/_instance');
                } finally {
                    $server->stop();
                }
            }
        } finally {
            $this->instant = null;
            Server::remove($eventsDir);
        }

        return $count;
    }

    /**
     * Step 2 on its own: with a fresh admin jar, logs in with the token, sends three
     * messages (one to the registered programme, where there is one), sends one again with
     * a spent nonce and hides the third. Does nothing for an event without push.
     */
    public function seedMessages(string $baseUrl, string $slug): void
    {
        $this->baseUrl = $baseUrl;
        $this->slug = $slug;
        $config = $this->config($slug);
        if (!in_array('push', $config['features'] ?? [], true)) {
            return;
        }
        $this->jars['admin'] = [];
        $step = 2;
        $admin = '/admin/notify';

        $this->save($step, 'admin-no-token', $this->request('GET', $admin, 'admin'));
        $login = $this->save($step, 'admin-token', $this->request('GET', $admin . '?token=' . self::ADMIN_TOKEN, 'admin'));
        $page = $this->save($step, 'admin-page', $this->follow($login, 'admin'));
        $this->check($page['code'] === 200, $step, 'the admin page does not answer 200 after the token');

        $send = function (string $name, array $page, string $target, string $title, string $body) use ($step, $admin): array {
            return $this->save($step, $name, $this->request('POST', $admin, 'admin', [], [
                'csrf' => self::field($page['body'], 'csrf'),
                'nonce' => self::field($page['body'], 'nonce'),
                'target' => $target,
                'title' => $title,
                'body' => $body,
                'signature' => 'Gate',
            ]));
        };

        $page = $send('send-empty-title', $page, '', '', 'tělo');
        $first = $send('send-one', $page, '', 'Brána jedna', "první řádek\ndruhý řádek");
        // the same form again, its nonce already spent
        $page = $send('send-one-again', $page, '', 'Brána jedna', "první řádek\ndruhý řádek");
        $this->check($first['code'] === 303, $step, 'the first send does not answer 303');
        $programme = $this->registeredProgramme($slug, $config);
        if ($programme !== null) {
            $sent = $send('send-two', $page, (string) $programme, 'Brána dvě', 'tělo');
            $page = $this->save($step, 'send-two-sent', $this->follow($sent, 'admin'));
        }
        $sent = $send('send-hidden', $page, '', 'Brána skrytá', 'tělo');
        $page = $this->save($step, 'send-hidden-sent', $this->follow($sent, 'admin'));

        $entry = self::logEntry($page['body'], 'Brána skrytá');
        if ($entry === null || preg_match('/<form method="post" action="([^"]+)"/', $entry, $form) !== 1) {
            $this->fail('the log shows no hide form for Brána skrytá', $step);
        }
        $this->save($step, 'hide', $this->request('POST', html_entity_decode($form[1]), 'admin', [], [
            'csrf' => self::field($page['body'], 'csrf'),
            'page' => preg_match('/name="page" value="([^"]*)"/', $entry, $m) === 1 ? html_entity_decode($m[1]) : '1',
            'hidden' => '1',
            'signature' => 'Gate',
        ], absolute: true));

        $log = $this->save($step, 'admin-log', $this->request('GET', $admin, 'admin'));
        $hidden = self::logEntry($log['body'], 'Brána skrytá');
        $this->check(self::logEntry($log['body'], 'Brána jedna') !== null, $step, 'the log does not show Brána jedna');
        $this->check($hidden !== null && str_contains($hidden, 'Skryto z Informací'), $step, 'the log shows Brána skrytá as visible');
    }

    /** One event's sequence, written under $dir. Returns the number of files written. */
    private function sequence(string $dir, string $slug): int
    {
        $this->dir = $dir;
        $this->slug = $slug;
        $this->jars = ['admin' => [], 'reader' => []];
        if (!mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $this->steps($slug);
        $this->dir = null;

        return count(glob($dir . '/*.txt') ?: []);
    }

    private function steps(string $slug): void
    {
        $config = $this->config($slug);
        $programme = $this->registeredProgramme($slug, $config);
        $same = ['Origin: ' . $this->baseUrl];
        $cross = ['Origin: ' . self::ELSEWHERE];

        // 1: the bare slug redirects to its trailing slash
        $this->save(1, 'redirect', $this->request('GET', '', 'reader'));

        // 2: the admin page and the message log
        $this->seedMessages($this->baseUrl, $slug);

        // 3: logged out
        $this->pages(3);

        // 4: cross-site writes are refused
        $this->save(4, 'cross-pair-start', $this->request('POST', '/profil/propojit', 'reader', $cross));
        $this->save(4, 'cross-logout', $this->request('POST', '/profil/odpojit', 'reader', $cross));
        if ($programme !== null) {
            $this->save(4, 'cross-register', $this->request('POST', '/programy/' . $programme . '/prihlasit', 'reader', $cross));
        }

        // 5: the pairing, approved by the local source on the first poll
        $this->save(5, 'pair-start', $this->request('POST', '/profil/propojit', 'reader', $same));
        $this->save(5, 'pair-profil', $this->request('GET', '/profil', 'reader'));
        $poll = $this->save(5, 'pair-poll', $this->request('GET', '/profil/propojit/stav', 'reader'));
        $answer = json_decode($poll['body'], true);
        $this->check(
            is_array($answer) && ($answer['status'] ?? null) === 'approved' && is_string($answer['next'] ?? null) && $answer['next'] !== '',
            5,
            'the poll does not answer {"status":"approved","next":"<page>"}',
        );

        // 6: paired
        $this->pages(6);

        // 7: unregister and register again
        if ($programme !== null) {
            foreach (['unregister' => 'odhlasit', 'register' => 'prihlasit'] as $name => $verb) {
                $write = $this->save(7, $name, $this->request('POST', '/programy/' . $programme . '/' . $verb, 'reader', $same));
                $this->check($write['code'] === 302, 7, sprintf('the %s does not answer 302', $name));
                $this->save(7, $name . '-target', $this->follow($write, 'reader'));
            }
        }

        // 8: logout
        $this->save(8, 'logout', $this->request('POST', '/profil/odpojit', 'reader', $same));
        $this->save(8, 'logout-profil', $this->request('GET', '/profil', 'reader'));
    }

    private function pages(int $step): void
    {
        foreach (self::PAGES as $name => $path) {
            $this->save($step, $name, $this->request('GET', $path, 'reader'));
            if (in_array($name, self::SCREENS, true)) {
                $this->save($step, $name . '-screen', $this->request('GET', $path, 'reader', ['X-Screen: 1']));
            }
        }
    }

    /** The instance app: the picker, the health check and its 404. */
    private function instance(string $dir): int
    {
        $this->dir = $dir;
        $this->slug = '';
        $this->jars = ['reader' => []];
        if (!mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $this->save(1, 'picker', $this->request('GET', '/', 'reader', absolute: true));
        $this->save(2, 'health', $this->request('GET', '/health', 'reader', absolute: true));
        $this->save(3, 'not-found', $this->request('GET', '/neexistuje', 'reader', absolute: true));
        $this->dir = null;

        return 3;
    }

    /** A temp directory of symlinks to every event under events/ and tests/fixtures/events/. */
    private function eventsDir(): string
    {
        $dir = sys_get_temp_dir() . '/gate-events-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o700)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        foreach ([$this->root() . '/events', $this->root() . '/tests/fixtures/events'] as $source) {
            foreach (glob($source . '/*', GLOB_ONLYDIR) ?: [] as $event) {
                // an event left out silently would be a stable dump of too little
                if (!symlink($event, $dir . '/' . basename($event))) {
                    Server::remove($dir);

                    throw new \RuntimeException(sprintf('Cannot link the event %s into %s', basename($event), $dir));
                }
            }
        }

        return $dir;
    }

    private function eventDir(string $slug): string
    {
        foreach ([$this->root() . '/events/', $this->root() . '/tests/fixtures/events/'] as $source) {
            if (is_file($source . $slug . '/config.php')) {
                return $source . $slug;
            }
        }

        throw new \RuntimeException('No event ' . $slug);
    }

    /** @return array<string, mixed> */
    private function config(string $slug): array
    {
        $config = require $this->eventDir($slug) . '/config.php';

        return is_array($config) ? $config : [];
    }

    /**
     * The first programme of participant 1 in the event's registered.json, or null for an
     * event without that file or without the programs feature.
     *
     * @param array<string, mixed> $config
     */
    private function registeredProgramme(string $slug, array $config): ?int
    {
        if (!in_array('programs', $config['features'] ?? [], true)) {
            return null;
        }
        $file = $this->eventDir($slug) . '/fixtures/registered.json';
        if (!is_file($file)) {
            return null;
        }
        $registered = json_decode((string) file_get_contents($file), true);
        $first = is_array($registered) && is_array($registered['1'] ?? null) ? ($registered['1'][0] ?? null) : null;

        return is_int($first) ? $first : null;
    }

    /**
     * One request with a jar's cookies; redirects are not followed. $path is under the event
     * unless $absolute.
     *
     * @param list<string> $headers
     * @param array<string, string>|null $form a POST's form fields
     * @return array{code: int, status: string, headers: list<array{string, string}>, body: string}
     */
    private function request(string $method, string $path, string $jar, array $headers = [], ?array $form = null, bool $absolute = false): array
    {
        $url = $this->baseUrl . ($absolute || $this->slug === '' ? '' : '/' . $this->slug) . $path;
        $cookies = [];
        foreach ($this->jars[$jar] ?? [] as $name => $value) {
            $cookies[] = $name . '=' . $value;
        }
        if ($cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', $cookies);
        }
        $status = '';
        $received = [];
        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$received): int {
                $trimmed = rtrim($line, "\r\n");
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $status = $trimmed;
                    $received = [];
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $received[] = [$name, trim($value)];
                }

                return strlen($line);
            },
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form ?? []);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Expect:';
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        if (!is_string($body)) {
            $this->fail(sprintf('%s %s failed: %s', $method, $url, curl_error($curl)));
        }
        foreach ($received as [$name, $value]) {
            if (strcasecmp($name, 'Set-Cookie') === 0) {
                $this->setCookie($jar, $value);
            }
        }

        return [
            'code' => (int) (explode(' ', $status)[1] ?? 0),
            'status' => $status,
            'headers' => $received,
            'body' => $body,
        ];
    }

    /** Stores a Set-Cookie in the jar; the jar ignores expiry, the server's clock is not ours. */
    private function setCookie(string $jar, string $header): void
    {
        $pair = explode(';', $header, 2)[0];
        if (!str_contains($pair, '=')) {
            return;
        }
        [$name, $value] = array_map('trim', explode('=', $pair, 2));
        if (preg_match('/;\s*max-age=(-?\d+)/i', $header, $m) === 1 && (int) $m[1] <= 0) {
            unset($this->jars[$jar][$name]);

            return;
        }
        $this->jars[$jar][$name] = $value;
    }

    /**
     * GETs a redirect's target, its fragment dropped.
     *
     * @param array{code: int, status: string, headers: list<array{string, string}>, body: string} $response
     * @return array{code: int, status: string, headers: list<array{string, string}>, body: string}
     */
    private function follow(array $response, string $jar): array
    {
        $location = null;
        foreach ($response['headers'] as [$name, $value]) {
            if (strcasecmp($name, 'Location') === 0) {
                $location = $value;
            }
        }
        if ($location === null || !str_starts_with($location, '/')) {
            $this->fail(sprintf('expected a same-site redirect, got %s', $response['status']));
        }

        return $this->request('GET', explode('#', $location, 2)[0], $jar, absolute: true);
    }

    /**
     * Writes one response as NN-<name>.txt (when the gate is dumping) and returns it.
     *
     * @param array{code: int, status: string, headers: list<array{string, string}>, body: string} $response
     * @return array{code: int, status: string, headers: list<array{string, string}>, body: string}
     */
    private function save(int $step, string $name, array $response): array
    {
        if ($this->dir === null) {
            return $response;
        }
        $headers = array_values(array_filter(
            $response['headers'],
            static fn(array $header): bool => !in_array(strtolower($header[0]), self::SKIPPED_HEADERS, true),
        ));
        usort($headers, static fn(array $a, array $b): int => strcasecmp($a[0], $b[0]));
        $body = $response['body'];
        // the PDF itself says nothing a diff could show
        if ($name === 'handbook' && $response['code'] === 200) {
            $body = sprintf('sha256:%s bytes:%d', hash('sha256', $body), strlen($body));
        }
        $text = $response['status'] . "\n";
        foreach ($headers as [$header, $value]) {
            $text .= $header . ': ' . $value . "\n";
        }
        file_put_contents(sprintf('%s/%02d-%s.txt', $this->dir, $step, $name), $text . "\n" . $body);

        return $response;
    }

    private function check(bool $holds, int $step, string $what): void
    {
        if (!$holds) {
            $this->fail($what, $step);
        }
    }

    /** Aborts, naming the instant (inside dump()), the event and the step. */
    private function fail(string $what, ?int $step = null): never
    {
        $where = trim(($this->instant ?? '') . ' ' . ($this->slug === '' ? 'instance' : $this->slug));

        throw new \RuntimeException($where . ': ' . ($step === null ? '' : sprintf('step %d: ', $step)) . $what);
    }

    /** A hidden form field's value, the first on the page. */
    private static function field(string $html, string $name): string
    {
        return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
    }

    /** The admin log's entry titled $title, as markup, or null. */
    private static function logEntry(string $html, string $title): ?string
    {
        foreach (explode('<article class="notify-log-entry">', $html) as $i => $entry) {
            if ($i > 0 && str_contains($entry, '<h3>' . htmlspecialchars($title) . '</h3>')) {
                return explode('</article>', $entry, 2)[0];
            }
        }

        return null;
    }
}
