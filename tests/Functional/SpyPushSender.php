<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\PushSenderInterface;

final class SpyPushSender implements PushSenderInterface
{
    public array $calls = [];

    /** @var list<array{event: string, endpoint: string, title: string, body: string, icon: ?string, url: ?string}> */
    public array $welcomes = [];

    public bool $welcomeDelivered = true;

    public function sendToEvent(
        string $event,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
        ?array $tieCodes = null,
    ): array {
        $this->calls[] = [$title, $body, $icon, $event, $url, $tieCodes];

        return ['sent' => 2, 'removed' => 1];
    }

    public function sendToSubscription(
        string $event,
        string $endpoint,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
    ): bool {
        $this->welcomes[] = compact('event', 'endpoint', 'title', 'body', 'icon', 'url');

        return $this->welcomeDelivered;
    }
}
