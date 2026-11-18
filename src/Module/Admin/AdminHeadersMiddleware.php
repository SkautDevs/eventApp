<?php

declare(strict_types=1);

namespace App\Module\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Middleware\ErrorMiddleware;

/**
 * `Cache-Control: no-store` and `Referrer-Policy: no-referrer` on every admin response, a
 * 403 and the error page included: nothing of it belongs in a cache, and no page it links
 * to needs to know where the organiser came from. An exception would pass this middleware
 * by and reach the app's error middleware without these headers (the sender throwing is
 * an expected path, since the message is logged first), so it is rendered here through the
 * container's ErrorMiddleware, the same handler, and so reported once.
 */
final class AdminHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ErrorMiddleware $errors) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $response = $this->errors->handleException($request, $e);
        }

        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
