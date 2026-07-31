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
        foreach ($this->repository->all() as $row) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'publicKey' => $row['publicKey'],
                    'authToken' => $row['authToken'],
                ]),
                $payload,
            );
        }

        $sent = 0;
        $removed = 0;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired()) {
                $this->repository->delete((string) $report->getRequest()->getUri());
                $removed++;
            }
        }

        return ['sent' => $sent, 'removed' => $removed];
    }
}
