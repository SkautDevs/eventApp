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

    public function save(array $subscription): void
    {
        if (!isset($subscription['endpoint'], $subscription['keys']['p256dh'], $subscription['keys']['auth'])) {
            throw new \InvalidArgumentException('Neplatný tvar push odběru');
        }

        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO subscriptions (endpoint, public_key, auth_token, created_at)
             VALUES (:endpoint, :public_key, :auth_token, :created_at)'
        );
        $statement->execute([
            'endpoint' => $subscription['endpoint'],
            'public_key' => $subscription['keys']['p256dh'],
            'auth_token' => $subscription['keys']['auth'],
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
