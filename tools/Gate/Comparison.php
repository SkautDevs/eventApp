<?php

declare(strict_types=1);

namespace Tools\Gate;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

/**
 * Compares two dump trees file by file, both sides normalised (Normalizer) and, in
 * frontend mode, stripped of what a frontend task may change on purpose (frontend()).
 */
final readonly class Comparison
{
    /** The most lines of unified diff printed per differing file. */
    private const int DIFF_LINES = 40;

    /** The suffix of the render gate's style dumps, which are compared as they are. */
    private const string STYLE_DUMP = '.style.txt.gz';

    /** The suffix of the render gate's DOM dumps, beside each style dump. */
    private const string DOM_DUMP = '.dom.html.gz';

    /** A script element with a src, on a line of its own or inline. */
    private const string SCRIPT = '<script\b[^>]*\ssrc=[^>]*>\s*</script>';

    /** A stylesheet or module-preload link. */
    private const string LINK = '<link\b[^>]*\srel="(?:stylesheet|modulepreload)"[^>]*>';

    public function __construct(
        public int $files,
        public int $differences,
        public string $report,
    ) {}

    public static function run(string $left, string $right, bool $frontend): self
    {
        $paths = array_values(array_unique([...self::files($left), ...self::files($right)]));
        sort($paths, SORT_STRING);
        $differences = 0;
        $report = '';
        foreach ($paths as $path) {
            $a = self::read($left . '/' . $path, $frontend);
            $b = self::read($right . '/' . $path, $frontend);
            if ($a === $b) {
                continue;
            }
            $differences++;
            if ($a === null || $b === null) {
                $report .= $path . ': only in ' . ($a === null ? $right : $left) . "\n";

                continue;
            }
            $diff = self::diff($a, $b);
            if (str_ends_with($path, self::STYLE_DUMP)) {
                $dom = substr($path, 0, -strlen(self::STYLE_DUMP)) . self::DOM_DUMP;
                $diff = self::annotate($diff, self::document($left . '/' . $dom), self::document($right . '/' . $dom));
            }
            $report .= $path . "\n" . $diff;
        }

        return new self(count($paths), $differences, $report);
    }

    /**
     * Drops every script element with a src, every stylesheet and module-preload link and,
     * from a precache.json body, its version and its script and stylesheet assets.
     */
    public static function frontend(string $response): string
    {
        foreach ([self::SCRIPT, self::LINK] as $element) {
            // a whole line first, so no blank line is left behind, then inline ones
            $response = (string) preg_replace('~^[ \t]*' . $element . '[ \t]*\r?\n~m', '', $response);
            $response = (string) preg_replace('~' . $element . '~', '', $response);
        }

        $split = strpos($response, "\n\n");
        if (str_starts_with($response, '{')) {
            return self::precache($response) ?? $response;
        }
        if ($split !== false) {
            $body = self::precache(substr($response, $split + 2));
            if ($body !== null) {
                return substr($response, 0, $split + 2) . $body;
            }
        }

        return $response;
    }

    /** The precache list without its version and its script and stylesheet assets, or null when $body is not one. */
    private static function precache(string $body): ?string
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !array_key_exists('version', $data) || !is_array($data['assets'] ?? null)) {
            return null;
        }
        unset($data['version']);
        $data['assets'] = array_values(array_filter(
            $data['assets'],
            static fn(mixed $asset): bool => !is_string($asset) || preg_match('/\.(?:js|css)$/', explode('?', $asset, 2)[0]) !== 1,
        ));

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> every file under $dir, relative to it */
    private static function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }

        return $files;
    }

    private static function read(string $file, bool $frontend): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $content = (string) file_get_contents($file);
        if (str_ends_with($file, '.gz')) {
            $content = gzdecode($content);
            if (!is_string($content)) {
                throw new \RuntimeException('Not a gzip file: ' . $file);
            }
        }
        // the render gate's style dump holds no per-run value and no element to drop
        if (str_ends_with($file, self::STYLE_DUMP)) {
            return $content;
        }
        $content = Normalizer::normalize($content);

        return $frontend ? self::frontend($content) : $content;
    }

    /**
     * A triage aid: appends to each removed or added line of a style-dump diff the class
     * of the element its path names, looked up in the DOM dump of the same side (`?` when
     * the path does not resolve there, or there is no DOM dump).
     */
    private static function annotate(string $diff, ?\Dom\HTMLDocument $left, ?\Dom\HTMLDocument $right): string
    {
        $lines = explode("\n", $diff);
        foreach ($lines as $i => $line) {
            $side = $line[0] ?? '';
            if (($side !== '-' && $side !== '+') || str_starts_with($line, '---') || str_starts_with($line, '+++') || !str_contains($line, ' | ')) {
                continue;
            }
            $path = explode('::', substr(explode(' | ', $line, 2)[0], 1), 2)[0];
            $element = self::resolve($side === '-' ? $left : $right, $path);
            $lines[$i] .= sprintf('  [class=%s]', $element === null ? '?' : '"' . ($element->getAttribute('class') ?? '') . '"');
        }

        return implode("\n", $lines);
    }

    private static function document(string $file): ?\Dom\HTMLDocument
    {
        $html = is_file($file) ? gzdecode((string) file_get_contents($file)) : false;

        return is_string($html) ? \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR) : null;
    }

    /** The element a style-dump path (`html > body:nth-of-type(1) > p:nth-of-type(2)#x`) names, or null. */
    private static function resolve(?\Dom\HTMLDocument $document, string $path): ?\Dom\Element
    {
        $element = $document?->documentElement;
        foreach (array_slice(explode(' > ', $path), 1) as $step) {
            if ($element === null || preg_match('/^([^:#\s]+):nth-of-type\((\d+)\)/', $step, $m) !== 1) {
                return null;
            }
            $count = 0;
            $next = null;
            foreach ($element->children as $child) {
                if ($child->localName === $m[1] && ++$count === (int) $m[2]) {
                    $next = $child;
                    break;
                }
            }
            $element = $next;
        }

        return $element;
    }

    private static function diff(string $a, string $b): string
    {
        $diff = (new Differ(new StrictUnifiedDiffOutputBuilder(['fromFile' => 'a', 'toFile' => 'b'])))->diff($a, $b);
        $lines = explode("\n", rtrim($diff, "\n"));
        if (count($lines) > self::DIFF_LINES) {
            $more = count($lines) - self::DIFF_LINES;
            $lines = array_slice($lines, 0, self::DIFF_LINES);
            $lines[] = sprintf('… %d more diff lines', $more);
        }

        return implode("\n", $lines) . "\n";
    }
}
