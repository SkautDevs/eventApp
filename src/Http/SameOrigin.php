<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Whether a state-changing request came from this site's own pages. The TIE login and
 * logout use it: a cross-site form could otherwise log a reader in as the attacker (whose
 * code push.js would then bind to the reader's subscription) or log them out.
 *
 * Sec-Fetch-Site decides when the browser sends it (every current one does). Only without
 * it is Origin compared — by host alone, because behind a TLS-terminating proxy the scheme
 * and port PHP sees are not the ones the browser used. Neither header means no browser form.
 */
final class SameOrigin
{
    public static function allows(ServerRequestInterface $request): bool
    {
        $site = strtolower(trim($request->getHeaderLine('Sec-Fetch-Site')));
        if ($site !== '') {
            return $site === 'same-origin' || $site === 'none';
        }
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return true;
        }
        $host = parse_url($origin, \PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getUri()->getHost()) === 0;
    }
}
