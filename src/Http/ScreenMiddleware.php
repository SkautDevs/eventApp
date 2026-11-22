<?php

declare(strict_types=1);

namespace App\Http;

use App\Program\Freshness;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * Fragment mode. A request carrying `X-Screen: 1` renders the screen without the
 * shell: the templates pick their parent from the `fragment` global, so the same
 * route and the same handler serve both a whole page and a bare screen. A plain
 * request is byte-identical to what it was before this existed, which is what keeps
 * deep links, crawlers and a no-JS reader working.
 *
 * Next to `fragment` the middleware sets `screenPath`, the path that names the screen,
 * a global because slim/twig-view resolves `get_uri()`'s runtime exactly once and then
 * keeps it for the life of the `App` — the path would be stale from the second request
 * on, which no single-request web server notices and every test does. On a view address
 * (/programy/moje) ScreenAliasMiddleware, inside the routing middleware, then renames it
 * to the screen's own path (/programy). The third global, `pageAddress`, is the address
 * itself, path and query (`/obrok19/programy?den=20260916`), and nothing renames it: the
 * skip link uses it, so it stays on the address the page was served for, where
 * `<base href="/">` would send a bare `#…` to the picker. It also sets
 * `Cache-Control: PAGE_CACHE_CONTROL`, `no-store, no-cache, must-revalidate`, on every
 * HTML page and fragment that states no policy of its own, cookie or not.
 *
 * It sits outside the error middleware (the security headers, the CSP and the
 * transaction middleware wrap it in turn), which is what puts `Vary: X-Screen` on an
 * error response too. A thrown 404 or 500 is answered by the error middleware without
 * ever reaching the route, so a `Vary: X-Screen` set further in would be missing from
 * exactly the responses a shared cache is most likely to keep. The loader treats any
 * non-200 as "not a screen" and falls back to a real navigation, so a full error page is
 * never injected into a <section>.
 */
final readonly class ScreenMiddleware implements MiddlewareInterface
{
    /** Every HTML page and fragment without a policy of its own. */
    public const string PAGE_CACHE_CONTROL = 'no-store, no-cache, must-revalidate';

    public function __construct(
        private Twig $twig,
        private Freshness $freshness,
        // each request reads it once
        private ClockInterface $clock,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $env = $this->twig->getEnvironment();
        $env->addGlobal('fragment', $request->getHeaderLine('X-Screen') === '1');
        $uri = $request->getUri();
        $env->addGlobal('screenPath', $uri->getPath());
        $query = $uri->getQuery();
        $env->addGlobal('pageAddress', $uri->getPath() . ($query === '' ? '' : '?' . $query));
        // Request-scoped: in a long-lived app the container, and this one instance with
        // it, outlives the request, and a stale flag must not leak into the next screen.
        $this->freshness->reset($this->clock->now());

        // the two responses differ for the same URL, so anything caching them has to
        // key on the header as well
        $response = $handler->handle($request)->withHeader('Vary', 'X-Screen');
        // A page carries the identity (the app bar, the registered marking), so no shared
        // or HTTP cache keeps it: stated here for every page and fragment, cookie or not,
        // because Session leaves PHP's cache limiter off. A route with its own policy
        // (the admin pages' no-store) keeps it. The worker's Cache API copy is unaffected.
        // An untyped response counts as a page, so a redirect or a 204 gets the header
        // too — harmless, neither has a body worth caching.
        $type = $response->getHeaderLine('Content-Type');
        if (!$response->hasHeader('Cache-Control') && ($type === '' || str_starts_with($type, 'text/html'))) {
            $response = $response->withHeader('Cache-Control', self::PAGE_CACHE_CONTROL);
        }

        return $response;
    }
}
