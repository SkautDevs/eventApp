<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TypedConstantsTest extends TestCase
{
    public function testEveryClassConstantCarriesAType(): void
    {
        $root = dirname(__DIR__, 2);
        $untyped = [];

        foreach (['src', 'tests', 'bin', 'tools'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $files = new \RegexIterator(
                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)),
                '/\.php$/',
            );
            foreach ($files as $file) {
                foreach (self::untypedIn((string) file_get_contents($file->getPathname())) as $line) {
                    $untyped[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $line;
                }
            }
        }

        self::assertSame([], $untyped, "Untyped class constants:\n" . implode("\n", $untyped));
    }

    /** @return list<int> lines of class constants declared without a type */
    private static function untypedIn(string $code): array
    {
        $tokens = \PhpToken::tokenize($code);
        $depth = 0;
        $lines = [];

        foreach ($tokens as $i => $token) {
            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is('}')) {
                $depth--;
            } elseif ($token->is(T_CONST) && $depth > 0) {
                $next = [];
                for ($j = $i + 1; count($next) < 2 && isset($tokens[$j]); $j++) {
                    if (!$tokens[$j]->isIgnorable()) {
                        $next[] = $tokens[$j];
                    }
                }
                if (count($next) === 2 && $next[0]->is(T_STRING) && $next[1]->is('=')) {
                    $lines[] = $token->line;
                }
            }
        }

        return $lines;
    }
}
