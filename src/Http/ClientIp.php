<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The reader's address. Behind TRUSTED_PROXY_COUNT reverse proxies it is the entry that
 * many hops from the right end of X-Forwarded-For: everything left of what our own
 * proxies appended was written by the client and proves nothing. Anything that is not
 * an IP address, or a chain shorter than the proxy count, falls back to REMOTE_ADDR.
 */
final class ClientIp
{
    public static function of(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) && $remote !== '' ? $remote : 'unknown';

        $hops = self::trustedProxyCount();
        if ($hops === 0) {
            return $remote;
        }

        $chain = array_values(array_filter(
            array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For'))),
            static fn (string $entry): bool => $entry !== '',
        ));
        $index = count($chain) - $hops;
        if ($index < 0) {
            return $remote;
        }

        return filter_var($chain[$index], \FILTER_VALIDATE_IP) !== false ? $chain[$index] : $remote;
    }

    private static function trustedProxyCount(): int
    {
        $value = $_ENV['TRUSTED_PROXY_COUNT'] ?? '0';

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
