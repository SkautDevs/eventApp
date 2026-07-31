<?php

require __DIR__ . '/../vendor/autoload.php';

$keys = Minishlink\WebPush\VAPID::createVapidKeys();
echo "VAPID_PUBLIC_KEY={$keys['publicKey']}\n";
echo "VAPID_PRIVATE_KEY={$keys['privateKey']}\n";
