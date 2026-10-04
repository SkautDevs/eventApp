<?php

declare(strict_types=1);

namespace App\Push;

/**
 * Which hosts a subscription may name. The server POSTs a welcome to a new endpoint at
 * once, so without this an unauthenticated body could make it call any internal host.
 * A pattern starting with `*.` matches one or more labels in front of the rest;
 * anything else must match exactly. Hosts compare case-insensitively.
 */
final class EndpointPolicy
{
    public const DEFAULT_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        '*.push.apple.com',
        'updates.push.services.mozilla.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        '*.push.samsungosp.com',
    ];

    /** @var list<string> */
    private readonly array $hosts;

    /** @param list<string> $hosts */
    public function __construct(array $hosts)
    {
        $this->hosts = array_values(array_filter(
            array_map(static fn (mixed $host): string => strtolower(trim((string) $host)), $hosts),
            static fn (string $host): bool => $host !== '',
        ));
    }

    /** The built-in services plus the comma-separated PUSH_ENDPOINT_HOSTS (a test's or a dev setup's). */
    public static function fromEnvironment(): self
    {
        $extra = $_ENV['PUSH_ENDPOINT_HOSTS'] ?? '';

        return new self([...self::DEFAULT_HOSTS, ...explode(',', is_string($extra) ? $extra : '')]);
    }

    public function allows(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        // userinfo is how a URL hides its real host from a careless reader
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || str_starts_with($host, '[') || filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach ($this->hosts as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }
}
