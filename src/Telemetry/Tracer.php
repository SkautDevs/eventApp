<?php

declare(strict_types=1);

namespace App\Telemetry;

use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

/**
 * Child spans of the request's transaction. Without a client, or outside a transaction,
 * span() is exactly `$fn()`. Exceptions pass through untouched.
 */
final class Tracer
{
    /**
     * A key of `$data` holding a `\Closure(mixed $result): array`; what it returns is merged
     * into the span data once `$fn` has returned — e.g. how many pushes a batch delivered.
     */
    public const FROM_RESULT = '@result';

    public static function span(string $op, string $description, callable $fn, array $data = []): mixed
    {
        $hub = SentrySdk::getCurrentHub();
        $parent = $hub->getClient() === null ? null : $hub->getSpan();
        if ($parent === null) {
            return $fn();
        }

        $fromResult = $data[self::FROM_RESULT] ?? null;
        unset($data[self::FROM_RESULT]);

        $context = new SpanContext();
        $context->setOp($op);
        $context->setDescription($description);
        $span = $parent->startChild($context);
        $hub->setSpan($span);
        try {
            $result = $fn();
            if ($fromResult instanceof \Closure) {
                $data = $fromResult($result) + $data;
            }
            $span->setStatus(SpanStatus::ok());

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(SpanStatus::internalError());
            throw $e;
        } finally {
            $span->setData($data);
            $span->finish();
            $hub->setSpan($parent);
        }
    }

    /** A tag on the current scope, which the transaction carries when it is sent. */
    public static function tag(string $key, string $value): void
    {
        $hub = SentrySdk::getCurrentHub();
        if ($hub->getClient() === null) {
            return;
        }
        $hub->configureScope(static function (Scope $scope) use ($key, $value): void {
            $scope->setTag($key, $value);
        });
    }
}
