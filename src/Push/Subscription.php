<?php

declare(strict_types=1);

namespace App\Push;

use App\Storage\Row;

/**
 * One stored push subscription of an event, as SubscriptionRepository reads it: the push
 * service's endpoint, the browser's two keys, and the participant whose session saved it
 * (null when it was saved logged out).
 */
final readonly class Subscription
{
    public function __construct(
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
        public ?int $participantId,
    ) {}

    /**
     * A `subscriptions` row as PDO fetches it.
     *
     * @param array<mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            Row::string($row, 'endpoint'),
            Row::string($row, 'public_key'),
            Row::string($row, 'auth_token'),
            Row::nullableInt($row, 'participant_id'),
        );
    }
}
