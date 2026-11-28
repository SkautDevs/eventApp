<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrator-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->path = $this->dir . '/push.sqlite';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** @return array<string, int> column name => position in the primary key (0 = not in it) */
    private static function primaryKey(\PDO $pdo, string $table): array
    {
        $key = [];
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC) as $column) {
            $key[$column['name']] = (int) $column['pk'];
        }

        return $key;
    }

    /** @return list<string> */
    private static function columns(\PDO $pdo, string $table): array
    {
        return array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC), 'name');
    }

    /** @return list<string> */
    private static function tables(\PDO $pdo): array
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testAFreshDatabaseIsAtVersionTwoWithTheInboxTable(): void
    {
        $pdo = Database::open(':memory:');

        (new Migrator())->migrate($pdo);

        self::assertSame(2, Migrator::VERSION);
        self::assertSame(2, Migrator::version($pdo));
        self::assertSame(['inbox_messages', 'messages', 'pairing_attempts', 'subscribe_attempts', 'subscriptions'], self::tables($pdo));
        self::assertSame(
            ['id', 'event', 'sent_at', 'participant_id', 'nickname', 'kind', 'body', 'contact', 'handled', 'handled_at', 'handled_by'],
            self::columns($pdo, 'inbox_messages'),
        );
        self::assertSame(['event', 'handled', 'sent_at'], $pdo->query('PRAGMA index_info(inbox_messages_list)')->fetchAll(\PDO::FETCH_COLUMN, 2));
        self::assertSame(['event', 'participant_id', 'sent_at'], $pdo->query('PRAGMA index_info(inbox_messages_sender)')->fetchAll(\PDO::FETCH_COLUMN, 2));
    }

    /** A production file that ran step 1 before step 2 existed. */
    public function testAVersionOneDatabaseMovesToTwoAndKeepsItsRows(): void
    {
        $pdo = Database::open($this->path);
        // step1 is private; calling it through reflection builds exactly the shipped schema
        (new \ReflectionMethod(Migrator::class, 'step1'))->invoke(new Migrator(), $pdo);
        $pdo->exec('PRAGMA user_version = 1');
        $pdo->exec("INSERT INTO messages (event, sent_at, target_label, title, body, signature) VALUES ('korbo26', '2026-10-02 14:05:00', 'Všem', 'Změna', 'text', 'Lung')");
        $pdo->exec("INSERT INTO subscriptions (event, endpoint, public_key, auth_token, created_at) VALUES ('korbo26', 'https://push.example/a', 'k', 'a', '2026-01-01')");
        $messages = $pdo->query('SELECT * FROM messages')->fetchAll(\PDO::FETCH_ASSOC);
        $subscriptions = $pdo->query('SELECT * FROM subscriptions')->fetchAll(\PDO::FETCH_ASSOC);

        (new Migrator())->migrate($pdo);

        self::assertSame(2, Migrator::version($pdo));
        self::assertSame($messages, $pdo->query('SELECT * FROM messages')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertSame($subscriptions, $pdo->query('SELECT * FROM subscriptions')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertContains('inbox_messages', self::tables($pdo));
    }

    public function testVersionIsTheHighestStep(): void
    {
        // STEPS is private; reading it through reflection leaves its visibility alone
        $steps = (new \ReflectionClassConstant(Migrator::class, 'STEPS'))->getValue();

        self::assertSame([1 => 'step1', 2 => 'step2'], $steps);
        self::assertSame(max(array_keys($steps)), Migrator::VERSION);
    }

    public function testSubscriptionsHaveTheColumnsTheRepositoryUsesKeyedOnEventAndEndpoint(): void
    {
        $pdo = Database::open(':memory:');

        (new Migrator())->migrate($pdo);

        self::assertSame(
            ['event' => 1, 'endpoint' => 2, 'public_key' => 0, 'auth_token' => 0, 'created_at' => 0, 'participant_id' => 0, 'failures' => 0],
            self::primaryKey($pdo, 'subscriptions'),
        );
        $participantId = array_values(array_filter(
            $pdo->query('PRAGMA table_info(subscriptions)')->fetchAll(\PDO::FETCH_ASSOC),
            static fn(array $column): bool => $column['name'] === 'participant_id',
        ))[0];
        self::assertSame('INTEGER', $participantId['type']);
        self::assertSame(0, (int) $participantId['notnull']);
    }

    public function testMessagesHaveTheColumnsTheRepositoryUsesWithNullableCounts(): void
    {
        $pdo = Database::open(':memory:');

        (new Migrator())->migrate($pdo);

        self::assertSame(
            ['id', 'event', 'sent_at', 'programme_id', 'target_label', 'title', 'body', 'signature', 'sent', 'removed', 'failed', 'unreached', 'hidden', 'toggled_at', 'toggled_by'],
            self::columns($pdo, 'messages'),
        );
        $id = (new \App\Push\MessageRepository($pdo))->begin('korbo26', null, 'Všem', 'Změna', 'text', 'Lung');
        self::assertNull($pdo->query('SELECT sent FROM messages WHERE id = ' . $id)->fetchColumn(), 'sent accepts NULL until the send returns');
    }

    public function testTheAttemptTablesAndTheirIndexes(): void
    {
        $pdo = Database::open(':memory:');

        (new Migrator())->migrate($pdo);

        foreach (['pairing_attempts', 'subscribe_attempts'] as $table) {
            self::assertSame(['event', 'ip', 'attempted_at'], self::columns($pdo, $table));
            self::assertSame(
                ['event', 'ip', 'attempted_at'],
                $pdo->query('PRAGMA index_info(' . $table . '_lookup)')->fetchAll(\PDO::FETCH_COLUMN, 2),
            );
        }
    }

    /**
     * A file from a newer schema — or from before the one-step rewrite, which numbered up to
     * five — is not current: the app stops with a message naming both versions rather than
     * running against tables it does not know.
     */
    public function testAFileAheadOfTheSchemaIsRefused(): void
    {
        $pdo = Database::open(':memory:');
        $pdo->exec('PRAGMA user_version = 5');

        try {
            (new Migrator())->migrate($pdo);
            self::fail('a version-5 file was taken for current');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('5', $e->getMessage());
            self::assertStringContainsString((string) Migrator::VERSION, $e->getMessage());
        }
        self::assertSame(5, Migrator::version($pdo), 'the file is left as it was');
    }

    public function testMigratingTwiceIsANoOp(): void
    {
        $pdo = Database::open($this->path);
        (new Migrator())->migrate($pdo);
        $pdo->exec("INSERT INTO subscriptions (event, endpoint, public_key, auth_token, created_at) VALUES ('korbo26', 'https://push.example/a', 'k', 'a', '2026-01-01')");

        (new Migrator())->migrate($pdo);
        (new Migrator())->migrate(Database::open($this->path));

        self::assertSame(Migrator::VERSION, Migrator::version($pdo));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
    }

    public function testAStepThatThrowsLeavesTheVersionAtZeroAndCreatesNothing(): void
    {
        $pdo = Database::open($this->path);
        // the step creates its tables in order; this one collides after the others were created
        $pdo->exec('CREATE TABLE pairing_attempts (x INTEGER)');

        try {
            (new Migrator())->migrate($pdo);
            self::fail('the colliding table should have failed step 1');
        } catch (\PDOException) {
        }

        self::assertSame(0, Migrator::version($pdo));
        self::assertSame(['pairing_attempts'], self::tables($pdo), 'the tables created before the failure were rolled back');
    }

    /** A file restored with the wrong owner. The test runs as root in Docker, so read-only is simulated by the open flag. */
    public function testAReadOnlyFileFailsAndALaterWritableOpenMigrates(): void
    {
        $setup = Database::open($this->path);
        $setup->exec('PRAGMA user_version = 0');
        unset($setup);

        $readOnly = new \PDO('sqlite:' . $this->path, null, null, [self::sqlite('ATTR_OPEN_FLAGS') => self::sqlite('OPEN_READONLY')]);
        $readOnly->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        try {
            (new Migrator())->migrate($readOnly);
            self::fail('a read-only file cannot be migrated');
        } catch (\PDOException) {
        }
        unset($readOnly);

        $pdo = Database::open($this->path);
        self::assertSame(0, Migrator::version($pdo), 'the failed attempt changed nothing');
        self::assertSame([], self::tables($pdo));

        (new Migrator())->migrate($pdo);

        self::assertSame(Migrator::VERSION, Migrator::version($pdo));
    }

    /** Pdo\Sqlite's constant by name (PDO::SQLITE_* is deprecated in 8.5). */
    private static function sqlite(string $name): int
    {
        return constant(\Pdo\Sqlite::class . '::' . $name);
    }
}
