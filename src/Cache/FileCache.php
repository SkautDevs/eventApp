<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * One JSON file per key: `{"fetchedAt": "<DATE_ATOM>", "data": …}`. A write goes to a
 * temporary file in the same directory and is renamed over the entry, so a reader sees
 * the old file or the new one and never half of either; reading needs no lock. A
 * `<key>.lock` file next to the entry serialises *fetching* it (tryLock()/waitLock()), so
 * one request refreshes an entry while the others serve what is there. The directory
 * appears on the first write.
 */
final class FileCache
{
    private const KEY = '/^[a-z0-9-]+$/';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /** @var \Closure(resource, int, mixed): bool flock(), or a stand-in with its signature */
    private readonly \Closure $flock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $now the clock; tests pass a fixed one
     * @param (\Closure(resource, int, mixed): bool)|null $flock flock(); tests pass a filesystem that cannot lock
     */
    public function __construct(private readonly string $dir, ?\Closure $now = null, ?\Closure $flock = null)
    {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->flock = $flock ?? flock(...);
    }

    /** Null for a missing entry and for one that cannot be used — which is deleted. */
    public function get(string $key): ?CacheEntry
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $fetchedAt = is_array($decoded) && is_string($decoded['fetchedAt'] ?? null)
            ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $decoded['fetchedAt'])
            : false;
        if (!is_array($decoded) || !array_key_exists('data', $decoded) || $fetchedAt === false) {
            @unlink($path);

            return null;
        }

        return new CacheEntry($decoded['data'], $fetchedAt);
    }

    /** @throws \RuntimeException when the entry cannot be written; the message names the key only */
    public function set(string $key, mixed $data): void
    {
        $path = $this->path($key);
        // before tempnam(): on a missing directory it falls back to the system temp dir
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(sprintf('Cannot create the cache directory for entry %s', $key));
        }

        try {
            $json = json_encode(
                ['fetchedAt' => ($this->now)()->format(\DATE_ATOM), 'data' => $data],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Cannot encode cache entry %s', $key), previous: $e);
        }

        $temp = @tempnam($this->dir, $key);
        if ($temp === false || @file_put_contents($temp, $json) === false || !@rename($temp, $path)) {
            if (is_string($temp)) {
                @unlink($temp);
            }
            throw new \RuntimeException(sprintf('Cannot write cache entry %s', $key));
        }
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * The lock that lets one request fetch an entry while the others serve what is there.
     * flock on `<key>.lock`; non-blocking.
     *
     * @return (\Closure(): void)|null the release, or null while another process holds it.
     *         Where no lock file can be made the release is a no-op: no single-flight is
     *         better than no answer.
     */
    public function tryLock(string $key): ?\Closure
    {
        $path = $this->dir . '/' . basename($this->path($key), '.json') . '.lock';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0o775, true);
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return static function (): void {
            };
        }
        $wouldBlock = 0;
        if (!($this->flock)($handle, \LOCK_EX | \LOCK_NB, $wouldBlock)) {
            fclose($handle);
            // Only contention means "held elsewhere". A filesystem that cannot lock at all
            // (some NFS or shared-host mounts) would otherwise read as permanently held:
            // every reader would get the expired entry as `locked`, and nothing would refresh.
            if ($wouldBlock) {
                return null;
            }

            return static function (): void {
            };
        }

        return static function () use ($handle): void {
            flock($handle, \LOCK_UN);
            fclose($handle);
        };
    }

    /**
     * Polls for the lock for up to $seconds, then goes ahead without it. Only contention
     * makes it poll: where locking fails for another reason tryLock() already answers with
     * a no-op release, so this returns at once. Bounded on purpose:
     * kissj's own timeout is 10 s, and a lock outliving its holder must not hang a reader.
     *
     * @return \Closure(): void the release; a no-op when the wait ran out
     */
    public function waitLock(string $key, float $seconds): \Closure
    {
        $deadline = microtime(true) + $seconds;
        do {
            $release = $this->tryLock($key);
            if ($release !== null) {
                return $release;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return static function (): void {
        };
    }

    private function path(string $key): string
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new \InvalidArgumentException('A cache key is lower-case letters, digits and dashes');
        }

        return $this->dir . '/' . $key . '.json';
    }
}
