<?php

declare(strict_types=1);

namespace Tools\Gate;

/**
 * One PHP built-in server under a frozen clock (libfaketime), serving the app through
 * tools/Gate/front.php against a given events directory. Everything the output depends on
 * is set in its environment, so a local .env (which never overrides a set variable) changes
 * nothing. Its push database, sessions and stub state live in temp directories of its own.
 */
final class Server
{
    private const string FAKETIME_LIBRARY = '/usr/lib/faketime/libfaketime.so.1';

    private const string TIMEZONE = 'Europe/Prague';

    /** A page whose data-fetched-at is the request time on the Prague clock. */
    private const string CLOCK_PAGE = '/obrok27/offline';

    private const float START_SECONDS = 10.0;

    /** @var resource|null */
    private $process;

    private int $port = 0;

    /** @var list<string> */
    private array $dirs = [];

    private string $log = '';

    /** Starts the server with its clock frozen at $instant (Prague time) and returns its base URL. */
    public function start(string $instant, string $eventsDir): string
    {
        if ($this->process !== null) {
            throw new \LogicException('The gate server is already running');
        }
        if (!is_file(self::FAKETIME_LIBRARY)) {
            throw new \RuntimeException('libfaketime is missing: run the gate with `apk add --no-cache libfaketime tzdata`');
        }
        $root = dirname(__DIR__, 2);
        $this->port = self::freePort();
        $state = $this->tempDir('gate-state');
        $this->log = $state . '/server.log';

        $env = getenv();
        // one worker: the gate's requests are sequential and their order is part of the output
        unset($env['PHP_CLI_SERVER_WORKERS']);
        $env = [
            ...$env,
            'LD_PRELOAD' => self::FAKETIME_LIBRARY,
            'FAKETIME' => $instant,
            'TZ' => self::TIMEZONE,
            'GATE_EVENTS_DIR' => $eventsDir,
            'APP_DEBUG' => '0',
            'SENTRY_DSN' => '',
            'VAPID_SUBJECT' => 'mailto:gate@example.invalid',
            'PUSH_DB_PATH' => $this->tempDir('gate-push') . '/push.sqlite',
            'SESSION_PATH' => $this->tempDir('gate-sessions'),
            'STUB_STATE_PATH' => $this->tempDir('gate-stub'),
        ];
        foreach (glob($eventsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $env['ADMIN_TOKEN_' . strtoupper(str_replace('-', '_', basename($dir)))] = 'gate';
        }

        $process = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $this->port, '-t', $root . '/www', $root . '/tools/Gate/front.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']],
            $pipes,
            $root,
            array_map(strval(...), $env),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('The gate server did not start');
        }
        $this->process = $process;

        $deadline = microtime(true) + self::START_SECONDS;
        while (!$this->listening()) {
            if (!proc_get_status($process)['running'] || microtime(true) > $deadline) {
                $output = (string) @file_get_contents($this->log);
                $this->stop();

                throw new \RuntimeException("The gate server did not come up:\n" . $output);
            }
            usleep(50_000);
        }

        $baseUrl = 'http://127.0.0.1:' . $this->port;
        $this->proveClock($baseUrl, $instant);

        return $baseUrl;
    }

    /** Ends the server, waits for its port to close and removes its temp directories. */
    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            $deadline = microtime(true) + self::START_SECONDS;
            while ((proc_get_status($this->process)['running'] || $this->listening()) && microtime(true) < $deadline) {
                usleep(50_000);
            }
            proc_close($this->process);
            $this->process = null;
        }
        foreach ($this->dirs as $dir) {
            self::remove($dir);
        }
        $this->dirs = [];
    }

    /** Aborts unless the app sees $instant on the Prague clock (no tzdata: libc reads it as UTC). */
    private function proveClock(string $baseUrl, string $instant): void
    {
        $expected = (new \DateTimeImmutable($instant, new \DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM);
        $html = @file_get_contents($baseUrl . self::CLOCK_PAGE, false, stream_context_create(['http' => ['ignore_errors' => true]]));
        $seen = is_string($html) && preg_match('/data-fetched-at="([^"]*)"/', $html, $m) === 1 ? $m[1] : null;
        if ($seen !== $expected) {
            $this->stop();

            throw new \RuntimeException(sprintf(
                'The gate server\'s clock is not frozen at %s: %s says %s (is tzdata installed?)',
                $expected,
                self::CLOCK_PAGE,
                $seen ?? 'nothing',
            ));
        }
    }

    private function listening(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('No free port for the gate server');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o700)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $this->dirs[] = $dir;

        return $dir;
    }

    /** Removes $path and everything under it; a symlink is removed, never followed. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
