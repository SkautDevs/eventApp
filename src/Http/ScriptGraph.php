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
 * The graph is built at most once per instance (one per request: the container's), and
 * kept across requests in a small file under var/cache keyed by the tree's stat signature
 * (ScriptTree), so the files are parsed again only when one changes, is added, removed or
 * renamed.
 */
final class ScriptGraph
{
    /**
     * A static import or re-export at the start of a line; group 2 is the specifier. The
     * clause before `from` cannot hold a quote, a semicolon or a parenthesis, so neither
     * `import('x')` nor `import.meta` matches.
     */
    private const string IMPORT = '/^[ \t]*(?:import\s*(?:[^;\'"()]*?\bfrom\s*)?|export\s*(?:\*(?:\s*as\s+[\w$]+)?|\{[^}]*\})\s*from\s*)([\'"])([^\'"\n]+)\1/m';

    /** @var array<string, array{imports: list<string>, unresolved: list<string>}>|null relative path => its resolved imports and its unresolved specifiers */
    private ?array $graph = null;

    /** @param string|null $cacheDir where ScriptTree keeps the graph across requests; null builds it once per instance */
    public function __construct(
        private readonly string $docroot,
        private readonly ?string $cacheDir = null,
    ) {}

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
        $tree = ScriptTree::read($this->dir(), $this->cacheDir);
        $cached = self::validGraph($tree->get('graph'));
        if ($cached !== null) {
            return $this->graph = $cached;
        }
        $dir = $this->dir();
        $graph = [];
        $files = array_values(array_filter($tree->files, static fn(string $file): bool => str_ends_with($file, '.js')));
        foreach ($files as $file) {
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
        $tree->put('graph', $graph);

        return $this->graph = $graph;
    }

    /**
     * A cached graph as graph() returns it, or null when the value has another shape.
     *
     * @return array<string, array{imports: list<string>, unresolved: list<string>}>|null
     */
    private static function validGraph(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $graph = [];
        foreach ($value as $file => $node) {
            if (!is_string($file) || !is_array($node) || !isset($node['imports'], $node['unresolved'])) {
                return null;
            }
            $imports = $node['imports'];
            $unresolved = $node['unresolved'];
            if (!is_array($imports) || !array_is_list($imports) || !is_array($unresolved) || !array_is_list($unresolved)) {
                return null;
            }
            foreach ([...$imports, ...$unresolved] as $item) {
                if (!is_string($item)) {
                    return null;
                }
            }
            /** @var list<string> $imports */
            /** @var list<string> $unresolved */
            $graph[$file] = ['imports' => $imports, 'unresolved' => $unresolved];
        }

        return $graph;
    }

    /**
     * The file's import specifiers, in source order, comments stripped first (stripComments()).
     *
     * @return list<string>
     */
    private function specifiers(string $file): array
    {
        preg_match_all(self::IMPORT, self::stripComments((string) @file_get_contents($this->dir() . '/' . $file)), $matches);

        return $matches[2];
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
