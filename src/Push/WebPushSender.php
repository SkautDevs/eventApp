<?php

declare(strict_types=1);

namespace App\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class WebPushSender implements PushSenderInterface
{
    public function __construct(
        private readonly SubscriptionRepository $repository,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
    ) {
    }

    public function sendToEvent(
        string $event,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
        ?array $tieCodes = null,
    ): array {
        $rows = $this->repository->forEvent($event, $tieCodes);

        return \App\Telemetry\Tracer::span(
            'push.send',
            'event ' . $event,
            fn (): array => $this->deliver($event, $rows, $title, $body, $icon, $url),
            [
                'rows' => count($rows),
                \App\Telemetry\Tracer::FROM_RESULT => static fn (array $result): array => ['sent' => $result['sent'], 'removed' => $result['removed']],
            ],
        );
    }

    public function sendToSubscription(
        string $event,
        string $endpoint,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
    ): bool {
        // the op's name is fixed now; Round B adds its outcome tag
        return \App\Telemetry\Tracer::span('push.welcome', 'welcome ' . $event, function () use ($event, $endpoint, $title, $body, $icon, $url): bool {
            $row = $this->repository->find($event, $endpoint);

            return $row !== null && $this->deliver($event, [$row], $title, $body, $icon, $url)['sent'] === 1;
        });
    }

    /**
     * @param string $event every row belongs to it, and a dead row is removed from it only
     * @param list<array{endpoint: string, publicKey: string, authToken: string, tieCode: ?string}> $rows
     *
     * @return array{sent: int, removed: int}
     */
    private function deliver(string $event, array $rows, string $title, string $body, ?string $icon, ?string $url): array
    {
        // Without GMP or BCMath — the bare php:8.3-alpine dev container; the image installs GMP —
        // the library raises a notice that a displaying dev server prints into the response,
        // ahead of the subscribe JSON and the admin redirect alike. It is advice, not a fault.
        set_error_handler(
            static fn (int $errno, string $message): bool => str_contains($message, 'GMP or BCMath'),
            \E_USER_NOTICE,
        );
        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->vapidSubject,
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                ],
            ]);
        } finally {
            restore_error_handler();
        }

        $payload = json_encode(['title' => $title, 'body' => $body, 'icon' => $icon, 'url' => $url]);
        $removed = 0;
        foreach ($rows as $row) {
            // One unusable row must not cost everybody else their notification. The
            // library accepts a malformed key pair without complaint here and only
            // throws while encrypting inside flush(), which takes the whole batch down
            // with it and never reaches the cleanup below — so the row is checked, and
            // dropped, before it is ever queued.
            if (!self::isDeliverable($row)) {
                $this->repository->delete($event, $row['endpoint']);
                $removed++;
                continue;
            }

            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $row['endpoint'],
                        'publicKey' => $row['publicKey'],
                        'authToken' => $row['authToken'],
                    ]),
                    $payload,
                );
            } catch (\Throwable) {
                // not deleted: a throw at this point is about the payload, which is the
                // same for every row, rather than about this subscription
                continue;
            }
        }

        $sent = 0;
        try {
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    $this->repository->delete($event, (string) $report->getRequest()->getUri());
                    $removed++;
                }
            }
        } catch (\Throwable) {
            // whatever the batch managed is still worth reporting; the alternative is a
            // 500 on the admin form with no indication of what did go out
        }

        return ['sent' => $sent, 'removed' => $removed];
    }

    /**
     * The shape the encryption in `flush()` insists on: a base64url P-256 public key of
     * 65 bytes in uncompressed form (a leading 0x04), and an auth secret of 16 bytes.
     * Every real browser subscription has it; a row that does not can never receive a
     * notification, so it is dead weight rather than a subscriber.
     *
     * @param array{endpoint: string, publicKey: string, authToken: string} $row
     */
    private static function isDeliverable(array $row): bool
    {
        $publicKey = base64_decode(strtr($row['publicKey'], '-_', '+/'), true);
        $authToken = base64_decode(strtr($row['authToken'], '-_', '+/'), true);

        return $publicKey !== false
            && strlen($publicKey) === 65
            && $publicKey[0] === "\x04"
            && $authToken !== false
            && strlen($authToken) === 16;
    }
}
