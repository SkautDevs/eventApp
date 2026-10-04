<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/db-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testAFileConnectionIsInWalModeWithTheSharedPragmas(): void
    {
        $pdo = Database::open($this->dir . '/push.sqlite');

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testAnInMemoryConnectionAcceptsItsOwnJournalMode(): void
    {
        $pdo = Database::open(':memory:');

        self::assertSame('memory', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
    }

    public function testOpeningCreatesNoTable(): void
    {
        $pdo = Database::open($this->dir . '/push.sqlite');

        self::assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll());
        self::assertSame(0, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
    }

    public function testADirectoryThatDoesNotExistThrows(): void
    {
        $this->expectException(\PDOException::class);
        Database::open($this->dir . '/missing/push.sqlite');
    }
}
