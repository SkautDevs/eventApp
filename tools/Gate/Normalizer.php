<?php

declare(strict_types=1);

namespace Tools\Gate;

/**
 * Blanks the values in a dumped response that differ on every run of the same tree, and
 * nothing else. Each pattern is pinned to its context: a rule wider than its value would
 * hide a real difference (an 8-hex rule without its `.css?v=` would also blank a colour).
 */
final class Normalizer
{
    /** pattern => replacement, applied in this order */
    private const array RULES = [
        // the CSP nonce in the header
        "/'nonce-[A-Za-z0-9+\\/=_-]+'/" => "'nonce-N'",
        // the CSP nonce on an inline <script>; not data-nonce, not name="nonce"
        '/(?<![\w-])nonce="[A-Za-z0-9+\/=_-]+"/' => 'nonce="N"',
        // the session cookie's value and its expiry, in its own Set-Cookie line only
        '/^(Set-Cookie: eventapp=)[^;\r\n]*/mi' => '${1}S',
        '/^(Set-Cookie: eventapp=[^\r\n]*?;\s*expires=)[^;\r\n]*/mi' => '${1}E',
        // the admin form's CSRF token and one-time nonce
        '/(name="(?:csrf|nonce)" value=")[^"]*"/' => '${1}C"',
        // the VAPID public key, generated per run by tests/bootstrap.php
        '/(<meta name="vapid-public-key" content=")[^"]*"/' => '${1}V"',
        // AssetVersion's content hash on a stylesheet or a script
        '/(\.(?:css|js))\?v=[0-9a-f]{8}(?![0-9a-f])/' => '${1}?v=H',
        // the hashed script directory
        '#/js/[0-9a-f]{8}/#' => '/js/H/',
    ];

    public static function normalize(string $response): string
    {
        return (string) preg_replace(array_keys(self::RULES), array_values(self::RULES), $response);
    }
}
