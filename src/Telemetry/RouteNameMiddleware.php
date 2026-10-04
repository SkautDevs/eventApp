<?php

declare(strict_types=1);

namespace App\Telemetry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\TransactionSource;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

/**
 * Renames the transaction to '<METHOD> <route pattern>' — `POST /admin/notify/{id:[0-9]+}/hidden`,
 * not the concrete path — and tags it with the event. Added before the routing middleware,
 * i.e. inside it, so the route is already on the request. Slim keeps the pattern without
 * the base path, which is the slug-free name wanted. A request that matched no route
 * never gets here and keeps the name `unmatched`. The instance app passes no slug: it
 * names its routes but tags no event.
 */
final class RouteNameMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ?string $slug)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hub = SentrySdk::getCurrentHub();
        if ($hub->getClient() !== null) {
            $transaction = $hub->getTransaction();
            $name = self::nameFor($request);
            if ($transaction !== null && $name !== null) {
                $transaction->setName($name);
                $transaction->getMetadata()->setSource(TransactionSource::route());
            }
            if ($this->slug !== null) {
                Tracer::tag('event', $this->slug);
            }
        }

        return $handler->handle($request);
    }

    public static function nameFor(ServerRequestInterface $request): ?string
    {
        $route = $request->getAttribute(RouteContext::ROUTE);

        return $route instanceof RouteInterface ? $request->getMethod() . ' ' . $route->getPattern() : null;
    }
}
