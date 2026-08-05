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
                created_at TEXT NOT NULL
            )'
        );
    }

    /** Longest endpoint any push service is known to issue is well under this */
    private const MAX_FIELD_LENGTH = 2048;

    /**
     * The route behind this is unauthenticated, so the body is attacker-controlled:
     * anything that is not a string of plausible length, or an endpoint that is not an
     * https URL, is rejected here rather than stored and handed to the push library
     * later. `PushModule` turns the exception into a 400.
     */
    public function save(array $subscription): void
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
            'INSERT OR REPLACE INTO subscriptions (endpoint, public_key, auth_token, created_at)
             VALUES (:endpoint, :public_key, :auth_token, :created_at)'
        );
        $statement->execute([
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'created_at' => date('c'),
        ]);
    }

    /** @return list<array{endpoint: string, publicKey: string, authToken: string}> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT endpoint, public_key, auth_token FROM subscriptions')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'endpoint' => $row['endpoint'],
                'publicKey' => $row['public_key'],
                'authToken' => $row['auth_token'],
            ],
            $rows,
        );
    }

    public function delete(string $endpoint): void
    {
        $statement = $this->pdo->prepare('DELETE FROM subscriptions WHERE endpoint = :endpoint');
        $statement->execute(['endpoint' => $endpoint]);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn();
    }
}
