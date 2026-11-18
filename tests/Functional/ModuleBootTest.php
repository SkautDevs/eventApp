<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventCatalog;
use App\Module\Modules;
use App\Program\ProgramProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Tests\FixedClock;

/**
 * Modules are built for every request, so building one must cost nothing: a repository
 * or the provider in a module's constructor would make every page open SQLite.
 */
final class ModuleBootTest extends AppTestCase
{
    /** @return iterable<string, array{string}> */
    public static function everyEvent(): iterable
    {
        foreach ((new EventCatalog(dirname(__DIR__, 2) . '/events'))->slugs() as $slug) {
            yield $slug => [$slug];
        }
    }

    #[DataProvider('everyEvent')]
    public function testBuildingEveryModuleOpensNoDatabaseAndAsksNoProvider(string $slug): void
    {
        $opened = false;
        $app = $this->createApp($slug, [
            \PDO::class => function () use (&$opened): \PDO {
                $opened = true;

                return self::memoryDb();
            },
            ProgramProviderInterface::class => new ThrowingProgramProvider(),
        ]);
        $app->getContainer()->get(Modules::class)->all();
        self::assertFalse($opened);
    }

    /** The screens that open no database today. Domů does once the event has started, so it is asked before the start. */
    #[DataProvider('quietScreens')]
    public function testAScreenThatNeedsNoDatabaseOpensNone(string $slug, string $path, bool $paired): void
    {
        $opened = false;
        $app = $this->createApp($slug, [
            \PDO::class => function () use (&$opened): \PDO {
                $opened = true;

                return self::memoryDb();
            },
            ClockInterface::class => new FixedClock(new \DateTimeImmutable('2027-01-10 12:00:00')),
        ]);
        if ($paired) {
            $this->pair($app);
            $opened = false;
        }
        self::assertSame(200, $this->request($app, 'GET', $path)->getStatusCode());
        self::assertFalse($opened, $path);
    }

    /** @return list<array{string, string, bool}> */
    public static function quietScreens(): array
    {
        return [
            ['obrok27', '/', false], ['obrok27', '/', true], ['obrok27', '/profil', false], ['obrok27', '/profil', true],
            ['obrok27', '/mapa', false], ['obrok27', '/offline', false], ['obrok27', '/precache.json', false],
        ];
    }

    public function testACrossSitePairingStartCountsNoAttempt(): void
    {
        $opened = false;
        $app = $this->createApp('korbo26', [\PDO::class => function () use (&$opened): \PDO {
            $opened = true;

            return self::memoryDb();
        }]);
        self::assertSame(403, $this->request($app, 'POST', '/profil/propojit', [], ['Origin' => 'https://elsewhere.example'])->getStatusCode());
        self::assertFalse($opened);
    }
}
