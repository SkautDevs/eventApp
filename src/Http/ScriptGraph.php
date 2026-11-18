<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The static import graph of the native ES modules under <docroot>/js, read from their
 * source: `import … from './x.js'`, a bare `import './x.js'` and `export … from './x.js'`,
 * each at the start of a line and outside a comment (stripComments()). The layout preloads
 * every file an entry imports (imports()), and the graph's tests hold the tree to two rules: every import
 * names a file by a relative path (unresolved()), and every file is reached from an entry
 * (orphans()). An absolute `/js/…` specifier is refused, because it would bypass the
 * version prefix the page loaded the importer under (ScriptVersion).
 *
 * Each file's imports are memoised for the life of the PHP process by its path, mtime and
 * size, so a php-fpm worker parses a file once per deploy.
 */
final class ScriptGraph
{
    /**
     * A static import or re-export at the start of a line; group 2 is the specifier. The
     * clause before `from` cannot hold a quote, a semicolon or a parenthesis, so neither
     * `import('x')` nor `import.meta` matches.
     */
    private const string IMPORT = '/^[ \t]*(?:import\s*(?:[^;\'"()]*?\bfrom\s*)?|export\s*(?:\*(?:\s*as\s+[\w$]+)?|\{[^}]*\})\s*from\s*)([\'"])([^\'"\n]+)\1/m';

    /** @var array<string, list<string>> "<file>:<mtime>:<size>" => its specifiers, in source order */
    private static array $memo = [];

    /** @var array<string, array{imports: list<string>, unresolved: list<string>}>|null relative path => its resolved imports and its unresolved specifiers */
    private ?array $graph = null;

    public function __construct(private readonly string $docroot) {}

    /**
     * The entry's transitive static imports in the order they are evaluated (a module's own
     * imports before it, in the order it writes them), without the entry, each once, relative
     * to <docroot>/js. A cycle ends where it meets a file already visited.
     *
     * @return list<string>
     */
    public function imports(string $entry): array
    {
        $graph = $this->graph();
        $seen = [$entry => true];
        $order = [];
        $visit = static function (string $file) use (&$visit, &$seen, &$order, $graph): void {
            foreach ($graph[$file]['imports'] ?? [] as $import) {
                if (!isset($seen[$import])) {
                    $seen[$import] = true;
                    $visit($import);
                    $order[] = $import;
                }
            }
        };
        $visit($entry);

        return $order;
    }

    /**
     * Every import that names no file, as '<file> -> <specifier>': a relative path to nothing,
     * and every specifier that is not relative (`./` or `../`). Sorted by file, then in source order.
     *
     * @return list<string>
     */
    public function unresolved(): array
    {
        $unresolved = [];
        foreach ($this->graph() as $file => $node) {
            foreach ($node['unresolved'] as $specifier) {
                $unresolved[] = $file . ' -> ' . $specifier;
            }
        }

        return $unresolved;
    }

    /**
     * Every script no entry reaches: neither one of $entries nor imported by one. Sorted.
     *
     * @param list<string> $entries
     *
     * @return list<string>
     */
    public function orphans(array $entries): array
    {
        $reached = [];
        foreach ($entries as $entry) {
            $reached[$entry] = true;
            foreach ($this->imports($entry) as $file) {
                $reached[$file] = true;
            }
        }

        return array_values(array_filter(array_keys($this->graph()), static fn(string $file): bool => !isset($reached[$file])));
    }

    /** @return array<string, array{imports: list<string>, unresolved: list<string>}> */
    private function graph(): array
    {
        if ($this->graph !== null) {
            return $this->graph;
        }
        $dir = $this->dir();
        $graph = [];
        foreach ($this->files() as $file) {
            $imports = [];
            $unresolved = [];
            foreach ($this->specifiers($file) as $specifier) {
                $target = self::resolve($file, $specifier);
                if ($target === null || !is_file($dir . '/' . $target)) {
                    $unresolved[] = $specifier;
                } elseif (!in_array($target, $imports, true)) {
                    $imports[] = $target;
                }
            }
            $graph[$file] = ['imports' => $imports, 'unresolved' => $unresolved];
        }

        return $this->graph = $graph;
    }

    /**
     * Every .js file under <docroot>/js, relative, sorted.
     *
     * @return list<string>
     */
    private function files(): array
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'js') {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The file's import specifiers, in source order, comments stripped first (stripComments()).
     *
     * @return list<string>
     */
    private function specifiers(string $file): array
    {
        $path = $this->dir() . '/' . $file;
        clearstatcache(true, $path);
        $key = $path . ':' . (int) @filemtime($path) . ':' . (int) @filesize($path);
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        preg_match_all(self::IMPORT, self::stripComments((string) @file_get_contents($path)), $matches);

        return self::$memo[$key] = $matches[2];
    }

    /**
     * The source without its comments. A `//` comment goes up to its line break, which stays;
     * a block comment becomes the line breaks it spanned, or one space, so every line keeps
     * its start. A comment marker inside a '…', "…" or `…` literal (escapes honoured) is
     * text, not a comment. Regular expression literals are not recognised: a `/'/` would read
     * as the start of a string, and no script here writes one.
     */
    public static function stripComments(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $i = 0;
        while ($i < $length) {
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';
            if ($char === '\'' || $char === '"' || $char === '`') {
                $end = $i + 1;
                while ($end < $length && $source[$end] !== $char) {
                    $end += $source[$end] === '\\' ? 2 : 1;
                }
                $out .= substr($source, $i, $end + 1 - $i);
                $i = $end + 1;
            } elseif ($char === '/' && $next === '/') {
                $end = strpos($source, "\n", $i);
                $i = $end === false ? $length : $end;
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $breaks = substr_count($source, "\n", $i, $end - $i);
                $out .= $breaks > 0 ? str_repeat("\n", $breaks) : ' ';
                $i = $end;
            } else {
                $out .= $char;
                $i++;
            }
        }

        return $out;
    }

    /** The specifier resolved against the importing file, relative to <docroot>/js; null when it is not relative or leaves the tree. */
    private static function resolve(string $from, string $specifier): ?string
    {
        if (!str_starts_with($specifier, './') && !str_starts_with($specifier, '../')) {
            return null;
        }
        $parts = explode('/', dirname($from));
        if ($parts === ['.']) {
            $parts = [];
        }
        foreach (explode('/', $specifier) as $segment) {
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }

    private function dir(): string
    {
        return rtrim($this->docroot, '/') . '/js';
    }
}
