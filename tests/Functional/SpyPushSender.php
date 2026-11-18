<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\PushSenderInterface;
use App\Push\SendOutcome;
use App\Push\SendResult;

final class SpyPushSender implements PushSenderInterface
{
    public array $calls = [];

    /** @var list<array{event: string, endpoint: string, title: string, body: string, icon: ?string, url: ?string}> */
    public array $welcomes = [];

    /** what the next welcome comes back as */
    public SendOutcome $welcomeOutcome = SendOutcome::Delivered;

    /** what every sendToEvent() comes back as */
    public SendResult $result;

    public function __construct()
    {
        $this->result = new SendResult(3, 2, 1, 0);
    }

    public function sendToEvent(
        string $event,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
        ?array $participantIds = null,
        ?int $programme = null,
    ): SendResult {
        $this->calls[] = [$title, $body, $icon, $event, $url, $participantIds, $programme];

        return $this->result;
    }

    public function sendToSubscription(
        string $event,
        string $endpoint,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
    ): SendOutcome {
        $this->welcomes[] = compact('event', 'endpoint', 'title', 'body', 'icon', 'url');

        return $this->welcomeOutcome;
    }
}
