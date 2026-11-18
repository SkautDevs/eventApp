<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The version of the scripts is the content of the whole www/js/ tree: the first 8 hex
 * characters of a sha256 over every file's relative path and bytes, in path order. Every
 * script is served under that one version, as /js/<hash>/<path> (the three servers strip
 * the segment: docker/nginx.conf, www/.htaccess, bin/script-path.php), so a page asks for
 * every script of one deploy under one prefix, and a module's imports resolve under the
 * prefix its importer was loaded under. A changed, added or renamed file changes every
 * URL, which is what makes a year of `immutable` safe for the current deploy's URLs; the
 * last paragraph says what an older page gets.
 *
 * Computed at most once per instance (one per request: the container's), and kept across
 * requests in a small file under var/cache keyed by the tree's stat signature (ScriptTree):
 * a request pays one directory walk and one stat per file, and the tree is read and hashed
 * again only when a file changes, is added, removed or renamed.
 *
 * A stale page cannot ask for an older deploy's bytes: every server strips any 8-hex
 * segment (docker/nginx.conf, www/.htaccess, bin/script-path.php) and serves the current
 * file under it, with a year's `immutable`. So a page that still holds an older deploy's
 * HTML (the worker's offline copy) and fetches a file under its old hash that no cache
 * holds gets the current file, which the HTTP cache then keeps under the old URL for a
 * year. That page can run modules of two deploys side by side. The worker precaches the
 * whole tree under one hash, which keeps this rare, but it is not impossible.
 */
final class ScriptVersion
{
    private ?ScriptTree $tree = null;

    private ?string $hash = null;

    /** @var array<string, true>|null relative path => true */
    private ?array $known = null;

    /** @param string|null $cacheDir where ScriptTree keeps the hash across requests; null computes it once per instance */
    public function __construct(
        private readonly string $docroot,
        private readonly ?string $cacheDir = null,
    ) {}

    /** First 8 hex of a sha256 over every file under <docroot>/js: its relative path and its content, in path order. Once per instance, cached by the tree's signature. */
    public function hash(): string
    {
        if ($this->hash !== null) {
            return $this->hash;
        }
        $tree = $this->tree();
        $cached = $tree->get('version');
        if (is_string($cached) && preg_match('/^[0-9a-f]{8}$/', $cached) === 1) {
            return $this->hash = $cached;
        }
        $hash = $this->compute();
        $tree->put('version', $hash);

        return $this->hash = $hash;
    }

    /** '/js/<hash>/<path>'. Throws \RuntimeException when the file does not exist. */
    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        $this->known ??= array_fill_keys($this->files(), true);
        if (!isset($this->known[$path]) && !is_file($this->dir() . '/' . $path)) {
            throw new \RuntimeException(sprintf('Script "%s" does not exist under %s', $path, $this->dir()));
        }

        return '/js/' . $this->hash() . '/' . $path;
    }

    /**
     * Every file under <docroot>/js, relative, sorted.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return $this->tree()->files;
    }

    private function tree(): ScriptTree
    {
        return $this->tree ??= ScriptTree::read($this->dir(), $this->cacheDir);
    }

    private function dir(): string
    {
        return rtrim($this->docroot, '/') . '/js';
    }

    private function compute(): string
    {
        $context = hash_init('sha256');
        foreach ($this->files() as $path) {
            // the path and the length first, so no two trees can feed the same bytes
            $content = (string) @file_get_contents($this->dir() . '/' . $path);
            hash_update($context, $path . "\0" . strlen($content) . "\0" . $content);
        }

        return substr(hash_final($context), 0, 8);
    }
}
