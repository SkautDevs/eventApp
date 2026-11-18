<?php

declare(strict_types=1);

namespace App\Push;

use App\Time\SystemClock;
use Psr\Clock\ClockInterface;

final class SubscriptionRepository
{
    /** Longest endpoint any push service is known to issue is well under this */
    private const int MAX_FIELD_LENGTH = 2048;

    /** The schema is the Migrator's: a row is keyed by (event, endpoint). */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * The route behind this is unauthenticated, so the body is attacker-controlled:
     * anything that is not a string of plausible length, or an endpoint that is not an
     * https URL, is rejected here rather than stored and handed to the push library
     * later. `Module\Push\SubscribeAction` turns the exception into a 400.
     *
     * A known (event, endpoint) is updated in place and keeps its failure count: a re-send
     * proves the browser is alive, not that its endpoint is deliverable, and anyone can
     * re-post a body. Only new keys — a different subscription behind the same URL —
     * start the count again.
     */
    public function save(array $subscription, string $event, ?int $participantId = null): void
    {
        if (!isset($subscription['endpoint'], $subscription['keys']['p256dh'], $subscription['keys']['auth'])) {
            throw new \InvalidArgumentException('Invalid push subscription shape');
        }

        $endpoint = $subscription['endpoint'];
        $publicKey = $subscription['keys']['p256dh'];
        $authToken = $subscription['keys']['auth'];

        foreach (['endpoint' => $endpoint, 'p256dh' => $publicKey, 'auth' => $authToken] as $field => $value) {
            if (!is_string($value) || $value === '' || strlen($value) > self::MAX_FIELD_LENGTH) {
                throw new \InvalidArgumentException(sprintf('Push subscription field %s is not a string of plausible length', $field));
            }
        }

        if (filter_var($endpoint, \FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($endpoint), 'https://')) {
            throw new \InvalidArgumentException('Push subscription endpoint is not an https URL');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO subscriptions (event, endpoint, public_key, auth_token, created_at, participant_id)
             VALUES (:event, :endpoint, :public_key, :auth_token, :created_at, :participant_id)
             ON CONFLICT (event, endpoint) DO UPDATE SET
                 failures = CASE
                     WHEN subscriptions.public_key = excluded.public_key AND subscriptions.auth_token = excluded.auth_token
                     THEN subscriptions.failures ELSE 0 END,
                 public_key = excluded.public_key,
                 auth_token = excluded.auth_token,
                 created_at = excluded.created_at,
                 participant_id = excluded.participant_id',
        );
        $statement->execute([
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'created_at' => $this->clock->now()->format('c'),
            'event' => $event,
            'participant_id' => $participantId,
        ]);
    }

    /**
     * @param list<int>|null $participantIds null for every subscriber of the event; a list for
     *                                       only those saved by a session of one of these participants
     * @return list<array{endpoint: string, publicKey: string, authToken: string, participantId: ?int}>
     */
    public function forEvent(string $event, ?array $participantIds = null): array
    {
        $sql = 'SELECT endpoint, public_key, auth_token, participant_id FROM subscriptions WHERE event = ?';
        $params = [$event];
        if ($participantIds !== null) {
            if ($participantIds === []) {
                return [];
            }
            $ids = array_values(array_unique(array_map('intval', $participantIds)));
            $sql .= ' AND participant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = [...$params, ...$ids];
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(self::row(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array{endpoint: string, publicKey: string, authToken: string, participantId: ?int}|null */
    public function find(string $event, string $endpoint): ?array
    {
        $statement = $this->pdo->prepare('SELECT endpoint, public_key, auth_token, participant_id FROM subscriptions WHERE event = ? AND endpoint = ?');
        $statement->execute([$event, $endpoint]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::row($row);
    }

    /** @return list<int> the distinct participants this event's subscribers are logged in as */
    public function subscribedParticipantIds(string $event): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT participant_id FROM subscriptions WHERE event = :event AND participant_id IS NOT NULL');
        $statement->execute(['event' => $event]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array{endpoint: string, public_key: string, auth_token: string, participant_id: int|string|null} $row
     * @return array{endpoint: string, publicKey: string, authToken: string, participantId: ?int}
     */
    private static function row(array $row): array
    {
        return [
            'endpoint' => $row['endpoint'],
            'publicKey' => $row['public_key'],
            'authToken' => $row['auth_token'],
            'participantId' => $row['participant_id'] === null ? null : (int) $row['participant_id'],
        ];
    }

    /** Scoped to the event: the same browser may be subscribed to two events, and each keeps its row. */
    public function delete(string $event, string $endpoint): void
    {
        $statement = $this->pdo->prepare('DELETE FROM subscriptions WHERE event = :event AND endpoint = :endpoint');
        $statement->execute(['event' => $event, 'endpoint' => $endpoint]);
    }

    /**
     * One more failed send for each endpoint; rows that reach $limit are deleted.
     *
     * @param list<string> $endpoints
     * @return int how many rows were deleted
     */
    public function noteFailures(string $event, array $endpoints, int $limit): int
    {
        if ($endpoints === []) {
            return 0;
        }
        $deleted = 0;
        $this->pdo->beginTransaction();
        try {
            foreach (array_chunk(array_values(array_unique($endpoints)), 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $this->pdo->prepare("UPDATE subscriptions SET failures = failures + 1 WHERE event = ? AND endpoint IN ($in)")->execute([$event, ...$chunk]);
                $delete = $this->pdo->prepare("DELETE FROM subscriptions WHERE event = ? AND failures >= ? AND endpoint IN ($in)");
                $delete->execute([$event, $limit, ...$chunk]);
                $deleted += $delete->rowCount();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $deleted;
    }

    /** @param list<string> $endpoints delivered just now: their strike count starts again */
    public function clearFailures(string $event, array $endpoints): void
    {
        foreach (array_chunk(array_values(array_unique($endpoints)), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $this->pdo->prepare("UPDATE subscriptions SET failures = 0 WHERE event = ? AND failures > 0 AND endpoint IN ($in)")->execute([$event, ...$chunk]);
        }
    }

    public function count(?string $event = null): int
    {
        if ($event === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE event = :event');
        $statement->execute(['event' => $event]);

        return (int) $statement->fetchColumn();
    }
}
