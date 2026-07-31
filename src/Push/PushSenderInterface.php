<?php

declare(strict_types=1);

namespace App\Push;

interface PushSenderInterface
{
    /** @return array{sent: int, removed: int} */
    public function sendToAll(string $title, string $body, ?string $icon = null): array;
}
