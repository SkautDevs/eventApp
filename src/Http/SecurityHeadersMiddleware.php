<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The response headers that cost nothing and break nothing here: the app serves no
 * user-uploaded file, never frames itself, and asks for none of the powerful
 * features. The Content-Security-Policy is CspMiddleware's, because it needs a
 * per-request nonce; www/.htaccess and docker/nginx.conf repeat these four for static
 * files and deliberately not the CSP, which does nothing on a non-document. Still no
 * HSTS — that needs TLS to exist first, see docs/deployment.md.
 *
 * Each header is set only if the response has none, which is how the admin routes keep
 * `Referrer-Policy: no-referrer`. It sits outside the error middleware, like the screen
 * middleware, so the error responses get the headers too.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> */
    public const array HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=()',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        foreach (self::HEADERS as $name => $value) {
            // a route that needs a stricter value (the admin pages' no-referrer) keeps its own
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
