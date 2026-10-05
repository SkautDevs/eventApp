<?php

declare(strict_types=1);

namespace App\Push;

use App\Telemetry\Collector;
use App\Telemetry\Tracer;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class WebPushSender implements PushSenderInterface
{
    /**
     * Per push request. The library's default is 30 s; push services answer in well under
     * one, a reader is waiting on the welcome, and an admin batch has to finish inside
     * nginx's 45 s fastcgi_read_timeout, or the organiser sees a 504 and sends again.
     */
    public const TIMEOUT_SECONDS = 5;

    /** @var \Closure(): SubscriptionRepository */
    private readonly \Closure $repositoryFactory;

    private ?SubscriptionRepository $repository = null;

    /** @var \Closure(\Throwable): void */
    private readonly \Closure $report;

    /**
     * @param \Closure(): SubscriptionRepository $repository resolved on the first send, so
     *        building the sender — which Kernel does at boot for every push event — opens
     *        no database
     * @param array<string, mixed> $clientOptions extra Guzzle options; tests pass a mock handler
     * @param LoggerInterface $logger where a batch with failures leaves its one warning
     * @param (\Closure(\Throwable): void)|null $report where a swallowed throwable goes; Sentry by default
     */
    public function __construct(
        \Closure $repository,
        private readonly EndpointPolicy $endpoints,
        #[\SensitiveParameter] private readonly string $vapidPublicKey,
        #[\SensitiveParameter] private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
        private readonly array $clientOptions = [],
        private readonly LoggerInterface $logger = new NullLogger(),
        ?\Closure $report = null,
    ) {
        self::assertVapid($vapidPublicKey, $vapidPrivateKey, $vapidSubject);
        $this->repositoryFactory = $repository;
        $this->report = $report ?? Collector::collect(...);
    }

    public function sendToEvent(
        string $event,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
        ?array $tieCodes = null,
        ?int $programme = null,
    ): array {
        $rows = $this->repository()->forEvent($event, $tieCodes);

        $result = Tracer::span(
            'push.send',
            'event ' . $event,
            function () use ($event, $rows, $title, $body, $icon, $url, $tieCodes, $programme): array {
                $target = $tieCodes === null ? 'event' : 'programme';
                Tracer::spanTag('push.target', $target);

                $result = $this->deliver($event, $rows, $title, $body, $icon, $url, $programme);
                if ($result['failed'] > 0) {
                    // counts only: no endpoint, no key, no text of the message
                    $this->logger->warning('push.failed', [
                        'event' => $event,
                        'target' => $target,
                        'recipients' => count($rows),
                        'sent' => $result['sent'],
                        'removed' => $result['removed'],
                        'failed' => $result['failed'],
                    ]);
                }

                return $result;
            },
            [
                'push.recipients' => count($rows),
                Tracer::FROM_RESULT => static fn (array $result): array => [
                    'push.sent' => $result['sent'],
                    'push.removed' => $result['removed'],
                    'push.failed' => $result['failed'],
                ],
            ],
        );

        return ['sent' => $result['sent'], 'removed' => $result['removed']];
    }

    public function sendToSubscription(
        string $event,
        string $endpoint,
        string $title,
        string $body,
        ?string $icon = null,
        ?string $url = null,
    ): SendOutcome {
        return Tracer::span('push.welcome', 'welcome ' . $event, function () use ($event, $endpoint, $title, $body, $icon, $url): SendOutcome {
            $outcome = $this->welcome($event, $endpoint, $title, $body, $icon, $url);
            Tracer::spanTag('push.outcome', $outcome->value);

            return $outcome;
        });
    }

    /**
     * The library with this sender's timeout. Without GMP or BCMath — the bare
     * php:8.3-alpine dev container; the image installs GMP — its constructor raises a
     * notice that a displaying dev server prints into the response and Sentry's error
     * listener would file. It is advice, not a fault, so it is swallowed here.
     *
     * @param array{subject: string, publicKey: string, privateKey: string} $vapid
     * @param array<string, mixed> $clientOptions
     */
    public static function webPush(array $vapid, array $clientOptions = []): WebPush
    {
        set_error_handler(
            static fn (int $errno, string $message): bool => str_contains($message, 'GMP or BCMath'),
            \E_USER_NOTICE,
        );
        try {
            return new WebPush(['VAPID' => $vapid], [], self::TIMEOUT_SECONDS, $clientOptions);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * What the service worker receives. `programme` is the id of the programme a message is
     * for, or null for everyone: the worker forwards it to the open app (`news-updated`),
     * which then refreshes the Program screen as well as Novinky.
     */
    public static function payload(string $title, string $body, ?string $icon, ?string $url, ?int $programme): string
    {
        return (string) json_encode(['title' => $title, 'body' => $body, 'icon' => $icon, 'url' => $url, 'programme' => $programme]);
    }

    private function welcome(string $event, string $endpoint, string $title, string $body, ?string $icon, ?string $url): SendOutcome
    {
        $row = $this->repository()->find($event, $endpoint);
        if ($row === null) {
            // nothing stored to deliver to: as dead as a 410
            return SendOutcome::Rejected;
        }

        try {
            $result = $this->deliver($event, [$row], $title, $body, $icon, $url);
        } catch (\Throwable $e) {
            ($this->report)($e);

            return SendOutcome::Failed;
        }

        return match (true) {
            $result['sent'] === 1 => SendOutcome::Delivered,
            // a 404 or 410 from the push service, or a row no push service could ever accept
            $result['removed'] === 1 => SendOutcome::Rejected,
            default => SendOutcome::Failed,
        };
    }

    private function repository(): SubscriptionRepository
    {
        return $this->repository ??= ($this->repositoryFactory)();
    }

    /**
     * @param string $event every row belongs to it, and a dead row is removed from it only
     * @param list<array{endpoint: string, publicKey: string, authToken: string, tieCode: ?string}> $rows
     *
     * @return array{sent: int, removed: int, failed: int} failed: neither delivered nor
     *         removed — a refusal other than 404/410 (a VAPID mismatch is a 403), or a throw
     */
    private function deliver(string $event, array $rows, string $title, string $body, ?string $icon, ?string $url, ?int $programme = null): array
    {
        $webPush = self::webPush([
            'subject' => $this->vapidSubject,
            'publicKey' => $this->vapidPublicKey,
            'privateKey' => $this->vapidPrivateKey,
        ], $this->clientOptions);

        $payload = self::payload($title, $body, $icon, $url, $programme);
        $removed = 0;
        $failed = 0;
        $queued = 0;
        foreach ($rows as $row) {
            // One unusable row must not cost everybody else their notification. The
            // library accepts a malformed key pair without complaint here and only
            // throws while encrypting inside flush(), which takes the whole batch down
            // with it and never reaches the cleanup below — so the row is checked, and
            // dropped, before it is ever queued. So is a row whose host is not a known
            // push service: rows saved before the allow-list existed may name any URL,
            // and the server must not POST to it.
            if (!$this->endpoints->allows($row['endpoint']) || !self::isDeliverable($row)) {
                $this->repository()->delete($event, $row['endpoint']);
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
                $queued++;
            } catch (\Throwable $e) {
                // not deleted: a throw at this point is about the payload, which is the
                // same for every row, rather than about this subscription
                ($this->report)($e);
                $failed++;
                continue;
            }
        }

        $sent = 0;
        $answered = 0;
        try {
            foreach ($webPush->flush() as $report) {
                $answered++;
                if ($report->isSuccess()) {
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    $this->repository()->delete($event, (string) $report->getRequest()->getUri());
                    $removed++;
                } else {
                    $failed++;
                }
            }
        } catch (\Throwable $e) {
            // whatever the batch managed is still worth reporting; the alternative is a
            // 500 on the admin form with no indication of what did go out
            ($this->report)($e);
            $failed += $queued - $answered;
        }

        return ['sent' => $sent, 'removed' => $removed, 'failed' => $failed];
    }

    /**
     * The three VAPID settings, checked when the sender is built — Kernel builds it at boot
     * for every push event — so a misconfigured instance fails on its first request rather
     * than on the first tap of the button. No message carries a key.
     *
     * @throws \RuntimeException naming the variable that is wrong
     */
    private static function assertVapid(
        #[\SensitiveParameter] string $publicKey,
        #[\SensitiveParameter] string $privateKey,
        string $subject,
    ): void
    {
        if (!self::isP256PublicKey($publicKey)) {
            throw new \RuntimeException('VAPID_PUBLIC_KEY must be a base64url P-256 public key: 65 bytes starting 0x04 (bin/generate-vapid.php makes a pair)');
        }
        $private = self::base64Url($privateKey);
        if ($private === null || strlen($private) !== 32) {
            throw new \RuntimeException('VAPID_PRIVATE_KEY must be a base64url P-256 private key: 32 bytes (bin/generate-vapid.php makes a pair)');
        }
        if (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https://')) {
            throw new \RuntimeException('VAPID_SUBJECT must start with mailto: or https://');
        }
    }

    /**
     * The shape the encryption in `flush()` insists on: a base64url P-256 public key in
     * uncompressed form and an auth secret of 16 bytes. Every real browser subscription
     * has it; a row that does not can never receive a notification, so it is dead weight
     * rather than a subscriber.
     *
     * @param array{endpoint: string, publicKey: string, authToken: string} $row
     */
    private static function isDeliverable(array $row): bool
    {
        $authToken = self::base64Url($row['authToken']);

        return self::isP256PublicKey($row['publicKey']) && $authToken !== null && strlen($authToken) === 16;
    }

    /** 65 bytes, a leading 0x04: the VAPID public key and every subscriber's key alike. */
    private static function isP256PublicKey(string $value): bool
    {
        $bytes = self::base64Url($value);

        return $bytes !== null && strlen($bytes) === 65 && $bytes[0] === "\x04";
    }

    /** base64url, padding optional; null for an empty or malformed value. */
    private static function base64Url(string $value): ?string
    {
        $decoded = $value === '' ? false : base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
