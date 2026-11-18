<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The version of a file under the docroot is its content: the first 8 hex characters of
 * its sha256. A changed file gets a new URL by itself, which is what makes a year of
 * `immutable` safe and leaves nothing for an operator to bump. Memoised by path, mtime and
 * size for the rest of the request only: PHP clears static properties when a request ends
 * (php-fpm and the built-in server alike), so every request that links an asset stats it
 * and hashes it once. A long-lived process, such as the test suite, keeps the memo.
 */
final class AssetVersion
{
    /** @var array<string, string> "<file>:<mtime>:<size>" => hash */
    private static array $memo = [];

    public function __construct(private readonly string $docroot) {}

    public function hash(string $path): string
    {
        $file = $this->docroot . '/' . ltrim($path, '/');
        // a long-lived process must see a file replaced under it; this is not a stat
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        $size = @filesize($file);
        if ($mtime === false || $size === false) {
            throw new \RuntimeException(sprintf('Asset "%s" does not exist under %s', $path, $this->docroot));
        }

        return self::$memo[$file . ':' . $mtime . ':' . $size] ??= substr((string) hash_file('sha256', $file), 0, 8);
    }

    /** `css/base.css` → `css/base.css?v=1a2b3c4d`, the path returned as given */
    public function url(string $path): string
    {
        return $path . '?v=' . $this->hash($path);
    }
}
