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
    private const array STEPS = [
        1 => 'step1',
    ];

    public const int VERSION = 1;

    public function migrate(\PDO $pdo): void
    {
        // A file ahead of the code — a newer schema, or one from before the steps were
        // collapsed into one — holds tables this code does not know: refuse it rather than
        // take it for current.
        $found = self::version($pdo);
        if ($found > self::VERSION) {
            throw new \RuntimeException(sprintf(
                'The push database is at schema version %d, but this code expects version %d; remove or replace the file',
                $found,
                self::VERSION,
            ));
        }
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
     * The whole schema, fresh: the push subscriptions (each with the participant id of the
     * session that saved it, and WebPushSender's strike count), the message log (counts NULL
     * until the send returns, so a message is on the list even if the send dies), and the two
     * per-(event, address) counters, Push\SubscribeThrottle's and Auth\PairingThrottle's.
     */
    private function step1(\PDO $pdo): void
    {
        $pdo->exec(self::subscriptionsTable());
        $pdo->exec(self::messagesTable());
        $pdo->exec('CREATE TABLE subscribe_attempts (event TEXT NOT NULL, ip TEXT NOT NULL, attempted_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX subscribe_attempts_lookup ON subscribe_attempts (event, ip, attempted_at)');
        $pdo->exec('CREATE TABLE pairing_attempts (event TEXT NOT NULL, ip TEXT NOT NULL, attempted_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX pairing_attempts_lookup ON pairing_attempts (event, ip, attempted_at)');
    }

    private static function messagesTable(): string
    {
        return 'CREATE TABLE messages (
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

    private static function subscriptionsTable(): string
    {
        return 'CREATE TABLE subscriptions (
            event      TEXT NOT NULL,
            endpoint   TEXT NOT NULL,
            public_key TEXT NOT NULL,
            auth_token TEXT NOT NULL,
            created_at TEXT NOT NULL,
            participant_id INTEGER NULL,
            failures   INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (event, endpoint)
        ) WITHOUT ROWID';
    }
}
