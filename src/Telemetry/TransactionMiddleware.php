<?php

declare(strict_types=1);

namespace App\Telemetry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

/**
 * One transaction per request. Added last, so it is the outermost layer: the error
 * middleware's 500 is measured too. Every transaction starts as `unmatched` and
 * RouteNameMiddleware renames it to the route pattern once routing has matched; a 404 or
 * a 405 never gets that far and keeps the fixed name, so the paths a scanner tries never
 * become transaction names. /health is never measured.
 */
final class TransactionMiddleware implements MiddlewareInterface
{
    public const string UNMATCHED = 'unmatched';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hub = SentrySdk::getCurrentHub();
        if ($hub->getClient() === null || $request->getUri()->getPath() === '/health') {
            return $handler->handle($request);
        }

        $transaction = $hub->startTransaction(self::context());
        $hub->setSpan($transaction);
        try {
            $response = $handler->handle($request);
            $transaction->setHttpStatus($response->getStatusCode());

            return $response;
        } catch (\Throwable $e) {
            $transaction->setHttpStatus(500);
            throw $e;
        } finally {
            $transaction->finish();
            $hub->setSpan(null);
            $hub->getClient()?->flush();
        }
    }

    /** What every transaction starts as, before a route claims it. */
    public static function context(): TransactionContext
    {
        $context = new TransactionContext();
        $context->setName(self::UNMATCHED);
        $context->setOp('http.server');
        $context->setSource(TransactionSource::custom());

        return $context;
    }
}
