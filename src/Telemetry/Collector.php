<?php

declare(strict_types=1);

namespace App\Telemetry;

use Sentry\SentrySdk;
use Sentry\State\Scope;

/**
 * Files one exception with Sentry, grouped by where it was thrown rather than by its
 * message. Without a bound client the hub discards it.
 */
final class Collector
{
    public static function collect(\Throwable $t): void
    {
        $hub = SentrySdk::getCurrentHub();
        $hub->withScope(static function (Scope $scope) use ($hub, $t): void {
            $scope->setFingerprint([$t::class, $t->getFile(), (string) $t->getLine()]);
            $hub->captureException($t);
        });
    }
}
