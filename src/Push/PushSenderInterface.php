<?php

declare(strict_types=1);

namespace App\Push;

interface PushSenderInterface
{
    /**
     * @param string|null $url the page a tap on the notification opens; the service worker
     *                         only honours one inside the event's own scope
     * @param list<string>|null $tieCodes null reaches every subscriber of the event, a list
     *                                    only the subscriptions of those TIE codes
     *
     * @return array{sent: int, removed: int}
     */
    public function sendToEvent(
        string $event,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
        ?array $tieCodes = null,
    ): array;

    /**
     * One notification to one stored subscription of the event — the welcome a browser gets
     * right after it subscribes, which proves the whole chain works before anything matters.
     * A row found dead (Rejected) has been deleted by the time this returns.
     */
    public function sendToSubscription(
        string $event,
        string $endpoint,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
    ): SendOutcome;
}
