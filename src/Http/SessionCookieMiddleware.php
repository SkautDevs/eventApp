<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Authenticator;
use App\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The login cookie slides: every full page a logged-in reader gets re-issues it with a
 * fresh 30-day expiry (same ID). A `GET` answered 200 with a full HTML page, not a
 * fragment, for a logged-in reader calls `Session::refreshCookie()`; a fragment, a
 * redirect, JSON or an error page does not.
 * Outside the error middleware like the screen middleware, so it sees the final response.
 */
final readonly class SessionCookieMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Authenticator $auth,
        private Session $session,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $type = $response->getHeaderLine('Content-Type');
        if ($request->getMethod() === 'GET'
            && $request->getHeaderLine('X-Screen') !== '1'
            && $response->getStatusCode() === 200
            && ($type === '' || str_starts_with($type, 'text/html'))
            && $this->auth->isLogged()
        ) {
            $this->session->refreshCookie();
        }

        return $response;
    }
}
