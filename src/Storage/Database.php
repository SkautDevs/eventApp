<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The only place a PDO is created. Every connection gets the same pragmas; none of
 * them creates a table — the schema is the Migrator's job.
 */
final class Database
{
    public static function open(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // WAL lets readers carry on while one worker writes. ':memory:' answers 'memory'
        // instead, which is fine: nothing else can share an in-memory database anyway.
        $pdo->query('PRAGMA journal_mode=WAL')->fetchColumn();
        // a second worker waits for the write lock instead of failing at once
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA foreign_keys=ON');

        return $pdo;
    }
}
