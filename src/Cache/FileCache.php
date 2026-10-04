<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * One JSON file per key: `{"fetchedAt": "<DATE_ATOM>", "data": …}`. A write goes to a
 * temporary file in the same directory and is renamed over the entry, so a reader sees
 * the old file or the new one and never half of either; no lock is needed. The directory
 * appears on the first write.
 */
final class FileCache
{
    private const KEY = '/^[a-z0-9-]+$/';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /** @param (\Closure(): \DateTimeImmutable)|null $now the clock; tests pass a fixed one */
    public function __construct(private readonly string $dir, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
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

    private function path(string $key): string
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new \InvalidArgumentException('A cache key is lower-case letters, digits and dashes');
        }

        return $this->dir . '/' . $key . '.json';
    }
}
