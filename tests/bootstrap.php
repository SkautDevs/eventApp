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
        // the key as EventConfig::envKey() builds it
        $name = 'PROGRAM_PROVIDER_' . strtoupper(str_replace('-', '_', basename($dir)));
        $_ENV[$name] = 'local';
        putenv($name . '=local');
    }
}
