<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The version of the scripts is the content of the whole www/js/ tree: the first 8 hex
 * characters of a sha256 over every file's relative path and bytes, in path order. Every
 * script is served under that one version, as /js/<hash>/<path> (the three servers strip
 * the segment: docker/nginx.conf, www/.htaccess, bin/script-path.php), so a phone never
 * runs a script of one deploy beside a script of another — once the scripts import each
 * other, a module's imports resolve under its own prefix. A changed, added or renamed file
 * changes every URL, which is what makes a year of `immutable` safe.
 *
 * Memoised for the life of the PHP process by every file's path, mtime and size, so a
 * request pays one directory walk and one stat per file, and a php-fpm worker hashes the
 * tree once per deploy.
 */
final class ScriptVersion
{
    /** @var array<string, string> "<path>:<mtime>:<size>\n…" => hash */
    private static array $memo = [];

    public function __construct(private readonly string $docroot) {}

    /** First 8 hex of a sha256 over every file under <docroot>/js: its relative path and its content, in path order. Memoised by the files' paths, mtimes and sizes. */
    public function hash(): string
    {
        $dir = $this->dir();
        $key = '';
        foreach ($this->files() as $path) {
            $file = $dir . '/' . $path;
            // a long-lived process must see a file replaced under it; this is not a stat
            clearstatcache(true, $file);
            $key .= $path . ':' . (int) @filemtime($file) . ':' . (int) @filesize($file) . "\n";
        }

        return self::$memo[$dir . "\n" . $key] ??= $this->compute();
    }

    /** '/js/<hash>/<path>'. Throws \RuntimeException when the file does not exist. */
    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        if (!is_file($this->dir() . '/' . $path)) {
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
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($files, SORT_STRING);

        return $files;
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
