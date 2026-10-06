<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The reader's address. Behind TRUSTED_PROXY_COUNT reverse proxies, and only on a
 * connection from a loopback or private address or from one that TRUSTED_PROXIES names,
 * it is the entry that many hops from the right end of X-Forwarded-For: everything left
 * of what our own proxies appended was written by the client and proves nothing.
 * Anything that is not an IP address, or a chain shorter than the proxy count, falls back
 * to REMOTE_ADDR.
 */
final class ClientIp
{
    public static function of(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) && $remote !== '' ? $remote : 'unknown';

        $hops = self::trustedProxyCount();
        // The header is worth reading only on a connection from a proxy of ours, which is
        // a loopback or private address. A reader connecting straight from the internet
        // wrote X-Forwarded-For themselves, and the login limit must not believe it.
        // TRUSTED_PROXIES widens that to named public addresses (a CDN, a hosted balancer).
        if ($hops === 0 || !(self::isProxyAddress($remote) || self::isListedProxy($remote))) {
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

    /**
     * What the login and subscribe limits count by: an IPv4 address as it is, an IPv6 one
     * as its /64 — one phone or one home is handed a whole /64 and can walk through it.
     * An IPv4-mapped address (::ffff:a.b.c.d, from a dual-stack socket) is its IPv4 address,
     * or every IPv4 reader of such a server would share the one key ::/64.
     */
    public static function throttleKey(ServerRequestInterface $request): string
    {
        $ip = self::of($request);
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = (string) inet_pton($ip);
        $v4 = self::unmapIpv4($packed);
        if ($v4 !== null) {
            return (string) inet_ntop($v4);
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * Loopback (127.0.0.0/8, ::1) or private (10/8, 172.16/12, 192.168/16, fc00::/7),
     * an IPv4-mapped IPv6 address judged as the IPv4 address it carries.
     */
    private static function isProxyAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $packed = self::unmapIpv4($packed) ?? $packed;
        if (strlen($packed) === 4) {
            $n = unpack('N', $packed)[1];

            return ($n >> 24) === 127 || ($n >> 24) === 10 || ($n >> 20) === 0xAC1 || ($n >> 16) === 0xC0A8;
        }

        return $packed === inet_pton('::1') || (ord($packed[0]) & 0xFE) === 0xFC;
    }

    /**
     * Whether TRUSTED_PROXIES names the address: a comma-separated list of addresses and
     * CIDR ranges, IPv4 or IPv6. An entry that is neither is skipped, never fatal.
     */
    private static function isListedProxy(string $ip): bool
    {
        $raw = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $packed = self::unmapIpv4($packed) ?? $packed;

        foreach (explode(',', $raw) as $entry) {
            $parts = explode('/', trim($entry), 2);
            $network = @inet_pton($parts[0]);
            if ($parts[0] === '' || $network === false) {
                continue;
            }
            $network = self::unmapIpv4($network) ?? $network;
            $bits = strlen($network) * 8;
            if (isset($parts[1])) {
                if (!ctype_digit($parts[1]) || (int) $parts[1] > $bits) {
                    continue;
                }
                $bits = (int) $parts[1];
            }
            if (strlen($network) === strlen($packed) && self::prefixMatches($packed, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the first $bits bits of two packed addresses of one family agree. */
    private static function prefixMatches(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** The four IPv4 bytes of a packed ::ffff:0:0/96 address, or null for anything else. */
    private static function unmapIpv4(string $packed): ?string
    {
        return strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
            ? substr($packed, 12)
            : null;
    }

    private static function trustedProxyCount(): int
    {
        $value = $_ENV['TRUSTED_PROXY_COUNT'] ?? '0';

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
