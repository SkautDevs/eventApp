<?php

declare(strict_types=1);

namespace App\Push;

interface PushSenderInterface
{
    /**
     * @param string|null $url the page a tap on the notification opens; the service worker
     *                         only honours one inside the event's own scope
     * @param list<int>|null $participantIds null reaches every subscriber of the event, a list
     *                                       only the subscriptions of those participants
     * @param int|null $programme the programme a message is about, or null; it travels in
     *                            the payload so the open app can refresh that screen too
     *
     * @return SendResult recipients: the rows the send was addressed to; removed: rows
     *         found dead and deleted (a 404 or 410, an unusable key, a host off the
     *         allow-list, or the last of WebPushSender::MAX_FAILURES failures in a row — that
     *         one counts as failed, not removed); failed: neither delivered nor removed (a
     *         refusal other than 404/410, a timeout, a throw)
     */
    public function sendToEvent(
        string $event,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
        ?array $participantIds = null,
        ?int $programme = null,
    ): SendResult;

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
