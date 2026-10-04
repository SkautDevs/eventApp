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
