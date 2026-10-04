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

    private static function tables(\PDO $pdo): array
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** The CREATE TABLE today's SubscriptionRepository constructor runs, and today's messages table. */
    private function createTodaysSchema(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE subscriptions (
            endpoint TEXT PRIMARY KEY,
            public_key TEXT NOT NULL,
            auth_token TEXT NOT NULL,
            created_at TEXT NOT NULL,
            event TEXT NOT NULL DEFAULT '',
            tie_code TEXT NULL
        )");
        $pdo->exec('CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, event TEXT NOT NULL, sent_at TEXT NOT NULL,
            programme_id INTEGER NULL, target_label TEXT NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL,
            signature TEXT NOT NULL, sent INTEGER NOT NULL, removed INTEGER NOT NULL, unreached INTEGER NULL,
            hidden INTEGER NOT NULL DEFAULT 0, toggled_at TEXT NULL, toggled_by TEXT NULL
        )');
    }

    public function testAFreshFileReachesTheCurrentVersionWithTheFinalSchema(): void
    {
        $pdo = Database::open($this->path);

        (new Migrator())->migrate($pdo);

        self::assertSame(Migrator::VERSION, Migrator::version($pdo));
        self::assertContains('subscriptions', self::tables($pdo));
        self::assertContains('messages', self::tables($pdo));
        self::assertSame(
            ['event' => 1, 'endpoint' => 2, 'public_key' => 0, 'auth_token' => 0, 'created_at' => 0, 'tie_code' => 0],
            self::primaryKey($pdo, 'subscriptions'),
        );
    }

    public function testVersionIsTheHighestStep(): void
    {
        // STEPS is private; reading it through reflection leaves its visibility alone
        $steps = (new \ReflectionClassConstant(Migrator::class, 'STEPS'))->getValue();

        self::assertSame(max(array_keys($steps)), Migrator::VERSION);
    }

    public function testAnInMemoryDatabaseMigratesToo(): void
    {
        $pdo = Database::open(':memory:');

        (new Migrator())->migrate($pdo);

        self::assertSame(Migrator::VERSION, Migrator::version($pdo));
    }

    public function testTodaysFileKeepsItsRowsDropsTheEventlessOneAndEndsKeyedOnEventAndEndpoint(): void
    {
        $pdo = Database::open($this->path);
        $this->createTodaysSchema($pdo);
        $pdo->exec("INSERT INTO subscriptions VALUES ('https://push.example/a', 'PK', 'AT', '2026-09-01T10:00:00+02:00', 'korbo26', 'KORBO1')");
        $pdo->exec("INSERT INTO subscriptions VALUES ('https://push.example/b', 'PK2', 'AT2', '2026-09-01T10:00:00+02:00', 'obrok27', NULL)");
        $pdo->exec("INSERT INTO subscriptions VALUES ('https://push.example/old', 'k', 'a', '2019-01-01', '', NULL)");
        $pdo->exec("INSERT INTO messages (event, sent_at, target_label, title, body, signature, sent, removed) VALUES ('korbo26', '2026-09-02 10:00:00', 'Všem', 'Změna', 'text', 'Lung', 1, 0)");

        (new Migrator())->migrate($pdo);

        self::assertSame(Migrator::VERSION, Migrator::version($pdo));
        self::assertSame(
            [
                ['event' => 'korbo26', 'endpoint' => 'https://push.example/a', 'public_key' => 'PK', 'auth_token' => 'AT', 'created_at' => '2026-09-01T10:00:00+02:00', 'tie_code' => 'KORBO1'],
                ['event' => 'obrok27', 'endpoint' => 'https://push.example/b', 'public_key' => 'PK2', 'auth_token' => 'AT2', 'created_at' => '2026-09-01T10:00:00+02:00', 'tie_code' => null],
            ],
            $pdo->query('SELECT event, endpoint, public_key, auth_token, created_at, tie_code FROM subscriptions ORDER BY event')->fetchAll(\PDO::FETCH_ASSOC),
        );
        self::assertSame(1, self::primaryKey($pdo, 'subscriptions')['event']);
        self::assertSame(2, self::primaryKey($pdo, 'subscriptions')['endpoint']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(), 'messages are untouched');
        self::assertNotContains('subscriptions_new', self::tables($pdo));
    }

    public function testAPreMultiEventFileUpgradesThroughTheSamePath(): void
    {
        $pdo = Database::open($this->path);
        $pdo->exec('CREATE TABLE subscriptions (endpoint TEXT PRIMARY KEY, public_key TEXT NOT NULL, auth_token TEXT NOT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO subscriptions VALUES ('https://push.example/old', 'k', 'a', '2019-01-01')");

        (new Migrator())->migrate($pdo);

        // its rows belonged to no event, were never sent to and cannot be reached any more
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
        self::assertSame(1, self::primaryKey($pdo, 'subscriptions')['event']);
        self::assertArrayHasKey('tie_code', self::primaryKey($pdo, 'subscriptions'));
        self::assertContains('messages', self::tables($pdo));
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

    public function testAStepThatThrowsLeavesTheVersionAndTheRowsWhereTheyWere(): void
    {
        $pdo = Database::open($this->path);
        $pdo->exec('CREATE TABLE subscriptions (endpoint TEXT PRIMARY KEY, public_key TEXT NOT NULL, auth_token TEXT NOT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO subscriptions VALUES ('https://push.example/old', 'k', 'a', '2019-01-01')");
        // step 1 adds `event` first and only then creates subscriptions_new, which now collides
        $pdo->exec('CREATE TABLE subscriptions_new (x INTEGER)');

        try {
            (new Migrator())->migrate($pdo);
            self::fail('the colliding table should have failed step 1');
        } catch (\PDOException) {
        }

        self::assertSame(0, Migrator::version($pdo));
        self::assertArrayNotHasKey('event', self::primaryKey($pdo, 'subscriptions'), 'the ALTER that ran before the failure was rolled back');
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
    }

    /** Review Focus 1: a file restored with the wrong owner. The test runs as root in Docker, so read-only is simulated by the open flag. */
    public function testAReadOnlyFileFailsAndALaterWritableOpenMigrates(): void
    {
        $setup = Database::open($this->path);
        $this->createTodaysSchema($setup);
        $setup->exec("INSERT INTO subscriptions VALUES ('https://push.example/a', 'PK', 'AT', '2026-09-01', 'korbo26', NULL)");
        unset($setup);

        $readOnly = new \PDO('sqlite:' . $this->path, null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        $readOnly->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        try {
            (new Migrator())->migrate($readOnly);
            self::fail('a read-only file cannot be migrated');
        } catch (\PDOException) {
        }
        unset($readOnly);

        $pdo = Database::open($this->path);
        self::assertSame(0, Migrator::version($pdo), 'the failed attempt changed nothing');
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());

        (new Migrator())->migrate($pdo);

        self::assertSame(Migrator::VERSION, Migrator::version($pdo));
        self::assertSame('https://push.example/a', $pdo->query('SELECT endpoint FROM subscriptions')->fetchColumn());
    }

    public function testAFreshFileHasTheLoginAttemptsTableAndItsIndex(): void
    {
        $pdo = Database::open($this->path);

        (new Migrator())->migrate($pdo);

        self::assertSame(2, Migrator::VERSION);
        self::assertContains('tie_attempts', self::tables($pdo));
        self::assertSame(
            ['event', 'ip', 'attempted_at'],
            $pdo->query('PRAGMA index_info(tie_attempts_lookup)')->fetchAll(\PDO::FETCH_COLUMN, 2),
        );
    }

    public function testAVersionOneFileGainsOnlyStepTwo(): void
    {
        $pdo = Database::open($this->path);
        $pdo->exec("CREATE TABLE subscriptions (event TEXT NOT NULL, endpoint TEXT NOT NULL, public_key TEXT NOT NULL, auth_token TEXT NOT NULL, created_at TEXT NOT NULL, tie_code TEXT NULL, PRIMARY KEY (event, endpoint)) WITHOUT ROWID");
        $pdo->exec("INSERT INTO subscriptions VALUES ('korbo26', 'https://push.example/a', 'k', 'a', '2026-01-01', NULL)");
        $pdo->exec('PRAGMA user_version = 1');

        (new Migrator())->migrate($pdo);

        self::assertSame(2, Migrator::version($pdo));
        self::assertContains('tie_attempts', self::tables($pdo));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
        self::assertNotContains('messages', self::tables($pdo), 'step 1 did not run again');
    }
}
