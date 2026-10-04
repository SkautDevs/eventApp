<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/file-cache-' . bin2hex(random_bytes(4));
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('Europe/Prague'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    private function cache(): FileCache
    {
        return new FileCache($this->dir, fn (): \DateTimeImmutable => $this->now);
    }

    public function testSetThenGetReturnsTheDataAndTheWriteTime(): void
    {
        $data = ['programmes' => [['id' => 5, 'name' => 'Ukázková vycházka']]];

        $this->cache()->set('list', $data);
        $entry = $this->cache()->get('list');

        self::assertNotNull($entry);
        self::assertSame($data, $entry->data);
        self::assertSame('2026-10-04T12:00:00+02:00', $entry->fetchedAt->format(\DATE_ATOM));
        $file = json_decode((string) file_get_contents($this->dir . '/list.json'), true);
        self::assertSame('2026-10-04T12:00:00+02:00', $file['fetchedAt']);
        self::assertSame($data, $file['data']);
    }

    /** Review Focus 4: getSections() is keyed by id in display order, and a hit must say the same. */
    public function testSectionKeysAndOrderSurviveTheRoundTrip(): void
    {
        $data = [
            'sections' => [
                10 => ['id' => 10, 'title' => 'Putování', 'subTitle' => null, 'image' => null, 'attachment' => null],
                1 => ['id' => 1, 'title' => 'Hlavní program', 'subTitle' => null, 'image' => null, 'attachment' => null],
            ],
            'programmes' => [],
        ];
        $sequential = ['sections' => [0 => ['id' => 0, 'title' => 'Nultá']], 'programmes' => []];

        $this->cache()->set('list', $data);
        $this->cache()->set('tie-0123456789abcdef0123456789abcdef', $sequential);

        self::assertSame($data, $this->cache()->get('list')->data);
        self::assertSame([10, 1], array_keys($this->cache()->get('list')->data['sections']));
        self::assertSame($sequential, $this->cache()->get('tie-0123456789abcdef0123456789abcdef')->data);
    }

    public function testAnEntryIsFreshUpToTheTtlAndStaleOneSecondLater(): void
    {
        $this->cache()->set('list', []);
        $entry = $this->cache()->get('list');

        self::assertTrue($entry->isFresh(300, $this->now->modify('+300 seconds')));
        self::assertFalse($entry->isFresh(300, $this->now->modify('+301 seconds')));
        self::assertSame(300, $entry->ageAt($this->now->modify('+300 seconds')));
    }

    public function testTtlZeroIsNeverFresh(): void
    {
        $this->cache()->set('list', []);

        self::assertFalse($this->cache()->get('list')->isFresh(0, $this->now));
    }

    public function testAnEntryFromTheFutureIsNotFresh(): void
    {
        $this->cache()->set('list', []);

        self::assertFalse($this->cache()->get('list')->isFresh(300, $this->now->modify('-1 second')));
    }

    public function testAMissingKeyIsNullAndCreatesNothing(): void
    {
        self::assertNull($this->cache()->get('list'));
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testACorruptFileReadsAsNullAndIsRemoved(): void
    {
        mkdir($this->dir, 0o775, true);
        foreach ([
            'not json' => 'not json',
            'a bare list' => '[]',
            'no fetchedAt' => '{"data":[]}',
            'unparseable fetchedAt' => '{"fetchedAt":"yesterday","data":[]}',
            'no data' => '{"fetchedAt":"2026-10-04T12:00:00+02:00"}',
        ] as $case => $contents) {
            file_put_contents($this->dir . '/list.json', $contents);

            self::assertNull($this->cache()->get('list'), $case);
            self::assertFileDoesNotExist($this->dir . '/list.json', $case);
        }
    }

    public function testABadKeyThrows(): void
    {
        $cache = $this->cache();
        foreach (['', 'List', 'tie_x', '../escape', 'a/b', 'list.json'] as $key) {
            foreach ([
                'get' => static fn () => $cache->get($key),
                'set' => static fn () => $cache->set($key, []),
                'delete' => static fn () => $cache->delete($key),
            ] as $method => $call) {
                try {
                    $call();
                    self::fail(sprintf('%s(%s) did not throw', $method, var_export($key, true)));
                } catch (\InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testNoTempFileIsLeftBehind(): void
    {
        $this->cache()->set('list', ['a' => 1]);
        $this->cache()->set('list', ['a' => 2]);

        self::assertSame(['list.json'], array_map('basename', glob($this->dir . '/*') ?: []));
        self::assertSame(['a' => 2], $this->cache()->get('list')->data);
    }

    public function testTheDirectoryIsCreatedOnTheFirstWrite(): void
    {
        self::assertDirectoryDoesNotExist($this->dir);

        $this->cache()->set('list', []);

        self::assertDirectoryExists($this->dir);
    }

    /** Review Focus 3: the caller decides what a failed write means; the cache only says so. */
    public function testAWriteThatCannotCreateTheDirectoryThrows(): void
    {
        // a regular file where the directory should be: mkdir fails even for root
        $blocker = (string) tempnam(sys_get_temp_dir(), 'cache-blocker');
        $caught = null;
        try {
            (new FileCache($blocker . '/cache'))->set('list', []);
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            unlink($blocker);
        }

        self::assertNotNull($caught, 'the write did not fail');
        self::assertStringContainsString('list', $caught->getMessage());
    }

    public function testDeleteRemovesTheEntryAndToleratesAMissingOne(): void
    {
        $this->cache()->set('list', []);

        $this->cache()->delete('list');
        $this->cache()->delete('list');
        $this->cache()->delete('tie-0123456789abcdef0123456789abcdef');

        self::assertNull($this->cache()->get('list'));
    }
}
