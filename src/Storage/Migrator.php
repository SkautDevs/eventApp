<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Brings a database to VERSION, one numbered step at a time, recording progress in
 * PRAGMA user_version. Each step runs in its own BEGIN IMMEDIATE transaction: the write
 * lock is taken first, so a second worker starting the same step waits (busy_timeout),
 * then re-reads the version and skips a step somebody else already finished. A step
 * that fails rolls back whole and leaves the version where it was; the next request
 * tries again.
 *
 * Append new steps; never edit one that has shipped.
 */
final class Migrator
{
    /** step number => method; the highest number is VERSION */
    private const STEPS = [
        1 => 'step1',
        2 => 'step2',
        3 => 'step3',
        4 => 'step4',
        5 => 'step5',
    ];

    public const VERSION = 5;

    public function migrate(\PDO $pdo): void
    {
        foreach (self::STEPS as $number => $method) {
            if (self::version($pdo) >= $number) {
                continue;
            }
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                if (self::version($pdo) < $number) {
                    $this->{$method}($pdo);
                    $pdo->exec('PRAGMA user_version = ' . $number);
                }
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\PDOException) {
                    // SQLite may already have rolled back on its own (disk full, I/O error)
                }
                throw $e;
            }
        }
    }

    public static function version(\PDO $pdo): int
    {
        return (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    }

    /**
     * The schema as it should have been. A fresh file gets both tables in their final
     * shape; a file from before this round gets `subscriptions` rebuilt keyed on
     * (event, endpoint), without the rows whose event is empty — nothing ever sent to
     * them and nobody can re-subscribe them. `messages` is created if missing and
     * otherwise left alone.
     */
    private function step1(\PDO $pdo): void
    {
        if (!self::hasTable($pdo, 'subscriptions')) {
            $pdo->exec(self::subscriptionsTable('subscriptions'));
        } else {
            $columns = array_column($pdo->query('PRAGMA table_info(subscriptions)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
            // a file from before multi-event, then one from before targeting
            if (!in_array('event', $columns, true)) {
                $pdo->exec("ALTER TABLE subscriptions ADD COLUMN event TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('tie_code', $columns, true)) {
                $pdo->exec('ALTER TABLE subscriptions ADD COLUMN tie_code TEXT NULL');
            }
            $pdo->exec(self::subscriptionsTable('subscriptions_new'));
            $pdo->exec(
                "INSERT INTO subscriptions_new (event, endpoint, public_key, auth_token, created_at, tie_code)
                 SELECT event, endpoint, public_key, auth_token, created_at, tie_code FROM subscriptions WHERE event <> ''"
            );
            $pdo->exec('DROP TABLE subscriptions');
            $pdo->exec('ALTER TABLE subscriptions_new RENAME TO subscriptions');
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event TEXT NOT NULL,
                sent_at TEXT NOT NULL,
                programme_id INTEGER NULL,
                target_label TEXT NOT NULL,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                signature TEXT NOT NULL,
                sent INTEGER NOT NULL,
                removed INTEGER NOT NULL,
                unreached INTEGER NULL,
                hidden INTEGER NOT NULL DEFAULT 0,
                toggled_at TEXT NULL,
                toggled_by TEXT NULL
            )'
        );
    }

    /** The TIE login's failed attempts, counted per (event, ip) by LoginThrottle. */
    private function step2(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE tie_attempts (
                event        TEXT NOT NULL,
                ip           TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX tie_attempts_lookup ON tie_attempts (event, ip, attempted_at)');
    }

    /** WebPushSender's strikes: consecutive failed sends of a subscription (not 404/410). */
    private function step3(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE subscriptions ADD COLUMN failures INTEGER NOT NULL DEFAULT 0');
    }

    /** New push subscriptions, counted per (event, address) by Push\SubscribeThrottle. */
    private function step4(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE subscribe_attempts (event TEXT NOT NULL, ip TEXT NOT NULL, attempted_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX subscribe_attempts_lookup ON subscribe_attempts (event, ip, attempted_at)');
    }

    /**
     * messages with nullable counts and a `failed` column: a message is logged before it is
     * sent (counts NULL until the send returns), so it is on News even if the send dies.
     * SQLite cannot drop NOT NULL in place; the table is rebuilt with its ids kept, and
     * AUTOINCREMENT's high-water mark carried over, so no id is ever handed out twice. A file without
     * the table gets it in its final shape.
     */
    private function step5(\PDO $pdo): void
    {
        if (!self::hasTable($pdo, 'messages')) {
            $pdo->exec(self::messagesTable('messages'));

            return;
        }
        $pdo->exec(self::messagesTable('messages_new'));
        $pdo->exec(
            'INSERT INTO messages_new (id, event, sent_at, programme_id, target_label, title, body, signature, sent, removed, failed, unreached, hidden, toggled_at, toggled_by)
             SELECT id, event, sent_at, programme_id, target_label, title, body, signature, sent, removed, NULL, unreached, hidden, toggled_at, toggled_by FROM messages'
        );
        $seq = $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'messages'")->fetchColumn();
        $pdo->exec('DROP TABLE messages');
        $pdo->exec('ALTER TABLE messages_new RENAME TO messages');
        if ($seq !== false) {
            // the copy only knows the highest id still present; a newer one may have been handed out
            $statement = $pdo->prepare("UPDATE sqlite_sequence SET seq = MAX(seq, :seq) WHERE name = 'messages'");
            $statement->execute(['seq' => (int) $seq]);
        }
    }

    private static function messagesTable(string $name): string
    {
        return 'CREATE TABLE ' . $name . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event TEXT NOT NULL,
            sent_at TEXT NOT NULL,
            programme_id INTEGER NULL,
            target_label TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            signature TEXT NOT NULL,
            sent INTEGER NULL,
            removed INTEGER NULL,
            failed INTEGER NULL,
            unreached INTEGER NULL,
            hidden INTEGER NOT NULL DEFAULT 0,
            toggled_at TEXT NULL,
            toggled_by TEXT NULL
        )';
    }

    private static function subscriptionsTable(string $name): string
    {
        return 'CREATE TABLE ' . $name . ' (
            event      TEXT NOT NULL,
            endpoint   TEXT NOT NULL,
            public_key TEXT NOT NULL,
            auth_token TEXT NOT NULL,
            created_at TEXT NOT NULL,
            tie_code   TEXT NULL,
            PRIMARY KEY (event, endpoint)
        ) WITHOUT ROWID';
    }

    private static function hasTable(\PDO $pdo, string $name): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$name]);

        return $statement->fetchColumn() !== false;
    }
}
