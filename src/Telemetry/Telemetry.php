<?php

declare(strict_types=1);

namespace App\Telemetry;

use App\Kernel;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;

/**
 * Binds the Sentry client, or does nothing. Called from Kernel::boot() only — the one
 * place .env is read — and tests build apps through Kernel::create(), so no test ever
 * initialises Sentry. With an empty SENTRY_DSN every telemetry piece is a no-op.
 */
final class Telemetry
{
    public static function init(): void
    {
        $dsn = $_ENV['SENTRY_DSN'] ?? '';
        if (!is_string($dsn) || $dsn === '') {
            return;
        }

        $client = ClientBuilder::create([
            'dsn' => $dsn,
            'environment' => Kernel::debug() ? 'debug' : 'production',
            'release' => 'eventapp@' . self::release(dirname(__DIR__, 2)),
            'traces_sample_rate' => self::rate('SENTRY_TRACES_SAMPLE_RATE'),
            'profiles_sample_rate' => self::rate('SENTRY_PROFILES_SAMPLE_RATE'),
            // the SDK default, written out because it is a decision: no IPs, no cookies
            'send_default_pii' => false,
            'before_send' => Scrubber::scrub(...),
            'before_send_transaction' => Scrubber::scrub(...),
        ])->getClient();
        SentrySdk::init()->bindClient($client);
    }

    /** APP_RELEASE (the Docker build sets it), else the checked-out commit, else 'unknown'. */
    public static function release(string $root): string
    {
        $release = $_ENV['APP_RELEASE'] ?? '';
        if (is_string($release) && $release !== '') {
            return $release;
        }

        $head = @file_get_contents($root . '/.git/HEAD');
        if (!is_string($head)) {
            return 'unknown';
        }
        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $ref = @file_get_contents($root . '/.git/' . substr($head, 5));
            $head = is_string($ref) ? trim($ref) : '';
        }

        return preg_match('/^[0-9a-f]{40,64}$/', $head) === 1 ? substr($head, 0, 7) : 'unknown';
    }

    /** 0..1; a missing, empty or unparseable value is 0 */
    private static function rate(string $name): float
    {
        $value = $_ENV[$name] ?? '';

        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.0;
    }
}
