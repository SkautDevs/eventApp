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
 * middleware's 500 is measured too. The name starts as the raw path and RouteNameMiddleware
 * renames it to the route pattern once routing has run. /health is never measured.
 */
final class TransactionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hub = SentrySdk::getCurrentHub();
        if ($hub->getClient() === null || $request->getUri()->getPath() === '/health') {
            return $handler->handle($request);
        }

        $context = new TransactionContext();
        $context->setName($request->getMethod() . ' ' . $request->getUri()->getPath());
        $context->setOp('http.server');
        $context->setSource(TransactionSource::url());
        $transaction = $hub->startTransaction($context);
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
}
