<?php

declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Psr7\Response;

/**
 * The kissj responses under tests/fixtures/kissj/korbo26/, built from a real camp export
 * (Korbo, 16–20 Sep 2026) and kept verbatim — oddities included, on purpose — except that
 * every description is the plain text the contract asks kissj for, where the export had
 * Markdown and HTML entities. The unit and the functional test both read them, so the
 * path is written here and nowhere else.
 */
final class KorboResponses
{
    public const string LIST = 'programme-list.json';
    public const string TIE = 'participant-tie.json';

    public static function body(string $file): string
    {
        $path = __DIR__ . '/fixtures/kissj/korbo26/' . $file;
        $body = file_get_contents($path);
        if ($body === false) {
            throw new \RuntimeException(sprintf('Korbo fixture missing: %s', $path));
        }

        return $body;
    }

    public static function response(string $file): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], self::body($file));
    }

    /** @return array<string, mixed> the decoded file, for reading the expectations off the data itself */
    public static function decoded(string $file): array
    {
        return json_decode(self::body($file), true, flags: JSON_THROW_ON_ERROR);
    }
}
