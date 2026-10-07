<?php

declare(strict_types=1);

namespace App\Telemetry;

use App\Auth\UnknownParticipantException;
use GuzzleHttp\Exception\RequestException;
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
    public const string FROM_RESULT = '@result';

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
            $span->setStatus(self::statusFor($e));
            throw $e;
        } finally {
            $span->setData($data);
            $span->finish();
            $hub->setSpan($parent);
        }
    }

    /**
     * The status a failed span carries. An HTTP answer keeps its own meaning — kissj's 404
     * for an unknown TIE code is `not_found`, an answer rather than a fault, and so is the
     * exception the provider turns it into — and everything else is `internal_error`.
     */
    public static function statusFor(\Throwable $e): SpanStatus
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return SpanStatus::createFromHttpStatusCode($e->getResponse()->getStatusCode());
        }
        if ($e instanceof UnknownParticipantException) {
            return SpanStatus::notFound();
        }

        return SpanStatus::internalError();
    }

    /**
     * A tag on the span running right now — inside span()'s callable, that span — for the
     * counters Sentry derives from spans (push.outcome, push.new …). Unlike tag(), which
     * marks the whole transaction.
     */
    public static function spanTag(string $key, string $value): void
    {
        $hub = SentrySdk::getCurrentHub();
        if ($hub->getClient() === null) {
            return;
        }
        $hub->getSpan()?->setTags([$key => $value]);
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
