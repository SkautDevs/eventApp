<?php

declare(strict_types=1);

namespace App\Push;

final class SubscriptionRepository
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS subscriptions (
                endpoint TEXT PRIMARY KEY,
                public_key TEXT NOT NULL,
                auth_token TEXT NOT NULL,
                created_at TEXT NOT NULL,
                event TEXT NOT NULL DEFAULT \'\',
                tie_code TEXT NULL
            )'
        );

        $columns = array_column($this->pdo->query('PRAGMA table_info(subscriptions)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $missing = [
            // a database from before multi-event: its rows belong to no event and are never sent
            'event' => "ALTER TABLE subscriptions ADD COLUMN event TEXT NOT NULL DEFAULT ''",
            // a database from before targeting: its rows belong to nobody and get only event-wide messages
            'tie_code' => 'ALTER TABLE subscriptions ADD COLUMN tie_code TEXT NULL',
        ];
        foreach ($missing as $column => $ddl) {
            if (in_array($column, $columns, true)) {
                continue;
            }
            try {
                $this->pdo->exec($ddl);
            } catch (\PDOException $e) {
                // a concurrent boot got there first
                if (!str_contains($e->getMessage(), 'duplicate column')) {
                    throw $e;
                }
            }
        }
    }

    /** Longest endpoint any push service is known to issue is well under this */
    private const MAX_FIELD_LENGTH = 2048;

    /**
     * The route behind this is unauthenticated, so the body is attacker-controlled:
     * anything that is not a string of plausible length, or an endpoint that is not an
     * https URL, is rejected here rather than stored and handed to the push library
     * later. `PushModule` turns the exception into a 400.
     */
    public function save(array $subscription, string $event, ?string $tieCode = null): void
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
            'INSERT OR REPLACE INTO subscriptions (endpoint, public_key, auth_token, created_at, event, tie_code)
             VALUES (:endpoint, :public_key, :auth_token, :created_at, :event, :tie_code)'
        );
        $statement->execute([
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'created_at' => date('c'),
            'event' => $event,
            'tie_code' => $tieCode === null || $tieCode === '' ? null : strtoupper($tieCode),
        ]);
    }

    /**
     * @param list<string>|null $tieCodes null for every subscriber of the event; a list for
     *                                    only those logged in with one of these codes
     * @return list<array{endpoint: string, publicKey: string, authToken: string, tieCode: ?string}>
     */
    public function forEvent(string $event, ?array $tieCodes = null): array
    {
        $sql = 'SELECT endpoint, public_key, auth_token, tie_code FROM subscriptions WHERE event = ?';
        $params = [$event];
        if ($tieCodes !== null) {
            if ($tieCodes === []) {
                return [];
            }
            $codes = array_values(array_unique(array_map(static fn ($c): string => strtoupper((string) $c), $tieCodes)));
            $sql .= ' AND tie_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            $params = [...$params, ...$codes];
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(
            static fn (array $row): array => [
                'endpoint' => $row['endpoint'],
                'publicKey' => $row['public_key'],
                'authToken' => $row['auth_token'],
                'tieCode' => $row['tie_code'],
            ],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /** @return array{endpoint: string, publicKey: string, authToken: string, tieCode: ?string}|null */
    public function find(string $event, string $endpoint): ?array
    {
        $statement = $this->pdo->prepare('SELECT endpoint, public_key, auth_token, tie_code FROM subscriptions WHERE event = ? AND endpoint = ?');
        $statement->execute([$event, $endpoint]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'endpoint' => $row['endpoint'],
            'publicKey' => $row['public_key'],
            'authToken' => $row['auth_token'],
            'tieCode' => $row['tie_code'],
        ];
    }

    /** @return list<string> the distinct TIE codes this event's subscribers are logged in with */
    public function subscribedTieCodes(string $event): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT tie_code FROM subscriptions WHERE event = :event AND tie_code IS NOT NULL');
        $statement->execute(['event' => $event]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function delete(string $endpoint): void
    {
        $statement = $this->pdo->prepare('DELETE FROM subscriptions WHERE endpoint = :endpoint');
        $statement->execute(['endpoint' => $endpoint]);
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
