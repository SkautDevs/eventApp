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

    public function sendToAll(string $title, string $body, ?string $icon = null): array
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $this->vapidSubject,
                'publicKey' => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ],
        ]);

        $payload = json_encode(['title' => $title, 'body' => $body, 'icon' => $icon]);
        $removed = 0;
        foreach ($this->repository->all() as $row) {
            // One unusable row must not cost everybody else their notification. The
            // library accepts a malformed key pair without complaint here and only
            // throws while encrypting inside flush(), which takes the whole batch down
            // with it and never reaches the cleanup below — so the row is checked, and
            // dropped, before it is ever queued.
            if (!self::isDeliverable($row)) {
                $this->repository->delete($row['endpoint']);
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
                    $this->repository->delete((string) $report->getRequest()->getUri());
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
