<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Every shipped event has push, and a push event refuses to boot without a valid VAPID
// pair. The tests that boot through Kernel::boot() or build the real sender get this
// throwaway pair, generated per run, so no key material lives in the public repository.
// It overrides whatever the shell or a local .env holds (Dotenv never overwrites a set variable).
$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
foreach ([
    'VAPID_PUBLIC_KEY' => $keys['publicKey'],
    'VAPID_PRIVATE_KEY' => $keys['privateKey'],
    'VAPID_SUBJECT' => 'mailto:tests@example.invalid',
] as $name => $value) {
    $_ENV[$name] = $value;
    putenv($name . '=' . $value);
}

// PROGRAM_PROVIDER_<SLUG> is required for every event; every test reads the event's own
// fixture files, so none reaches a real kissj whatever a local .env names.
foreach ([dirname(__DIR__) . '/events', __DIR__ . '/fixtures/events'] as $eventsDir) {
    foreach (glob($eventsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        // the key as Environment::eventKey() builds it
        $name = 'PROGRAM_PROVIDER_' . strtoupper(str_replace('-', '_', basename($dir)));
        $_ENV[$name] = 'local';
        putenv($name . '=local');
    }
}

// CACHE_PATH (the kissj cache, the scripts' version and import graph) goes to a directory
// of this run's own, removed at the end: no test, in process or behind a server it starts,
// creates var/cache. A server inherits it from this process's environment.
$cache = sys_get_temp_dir() . '/eventapp-cache-' . bin2hex(random_bytes(6));
if (!mkdir($cache, 0o700)) {
    throw new \RuntimeException('Could not create the test cache directory ' . $cache);
}
$_ENV['CACHE_PATH'] = $cache;
putenv('CACHE_PATH=' . $cache);
// The check that this holds: a run that started without var/cache and ends with one fails.
$varCache = dirname(__DIR__) . '/var/cache';
$hadVarCache = is_dir($varCache);
register_shutdown_function(static function () use ($cache, $varCache, $hadVarCache): void {
    if (!$hadVarCache && is_dir($varCache)) {
        fwrite(STDERR, "\ntests/bootstrap.php: this run created var/cache; tests must use CACHE_PATH\n");
        exit(1);
    }
    if (!is_dir($cache)) {
        return;
    }
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        \assert($file instanceof \SplFileInfo);
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($cache);
});
