<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The files under <docroot>/js, read with one stat walk, and a small cache of what
 * ScriptVersion and ScriptGraph derive from them. PHP keeps no static property between
 * requests, so a value computed from the tree's content is kept in a file instead:
 * `<cacheDir>/scripts-<name>-<key>.json`, where the key is a sha256 of the tree's
 * signature (every file's relative path, mtime, size and inode). A changed, added,
 * removed or renamed file changes the signature, so the next request misses the cache
 * and computes afresh: no deploy step clears it. A same-size rewrite within the same
 * second keeps the signature, the collision AssetVersion documents too.
 *
 * Writing a new entry removes the older entries of the same name, since only one tree is
 * current. The cache is best effort: a directory that cannot be written, a missing or a
 * malformed entry, and no cache directory at all (null) each mean the value is computed.
 */
final readonly class ScriptTree
{
    /** @param list<string> $files */
    private function __construct(
        public array $files,
        public string $signature,
        private ?string $cacheDir,
    ) {}

    /** One stat walk of $jsDir: every file under it, relative and sorted, and their signature. */
    public static function read(string $jsDir, ?string $cacheDir): self
    {
        if (!is_dir($jsDir)) {
            return new self([], $jsDir . "\n", $cacheDir);
        }
        // a long-lived process must see a file replaced under it
        clearstatcache();
        $stats = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jsDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $stat = @stat($file->getPathname());
                $stats[substr($file->getPathname(), strlen($jsDir) + 1)] = $stat === false
                    ? '-'
                    : $stat['mtime'] . ':' . $stat['size'] . ':' . $stat['ino'];
            }
        }
        ksort($stats, SORT_STRING);
        $signature = $jsDir . "\n";
        foreach ($stats as $path => $stat) {
            $signature .= $path . ':' . $stat . "\n";
        }

        return new self(array_map(strval(...), array_keys($stats)), $signature, $cacheDir);
    }

    /** The cached value of $name for this tree, or null when there is none. */
    public function get(string $name): mixed
    {
        if ($this->cacheDir === null) {
            return null;
        }
        $json = @file_get_contents($this->path($name));
        if ($json === false) {
            return null;
        }

        return json_decode($json, true);
    }

    /** Store $value as $name for this tree and drop the older entries of $name; silent when it cannot. */
    public function put(string $name, mixed $value): void
    {
        if ($this->cacheDir === null) {
            return;
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o775, true);
        }
        if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
            return;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        $tmp = @tempnam($this->cacheDir, '.scripts-');
        if ($json === false || $tmp === false) {
            return;
        }
        $target = $this->path($name);
        // written aside and renamed into place, so a parallel request reads a whole entry or none
        if (@file_put_contents($tmp, $json) === false || (\function_exists('chmod') && !@chmod($tmp, 0o664)) || !@rename($tmp, $target)) {
            @unlink($tmp);

            return;
        }
        foreach (glob($this->cacheDir . '/scripts-' . $name . '-*.json') ?: [] as $old) {
            if ($old !== $target) {
                @unlink($old);
            }
        }
    }

    private function path(string $name): string
    {
        return $this->cacheDir . '/scripts-' . $name . '-' . substr(hash('sha256', $this->signature), 0, 16) . '.json';
    }
}
