<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * Names the screen a view address belongs to. A route whose argument `screen` is set
 * (`/programy/prehled` and `/programy/moje` name `/programy`, `/mapa/plan` names `/mapa`)
 * is another address of that screen, so the Twig global `screenPath` becomes the base path
 * plus `screen`: the section's data-screen is the same on every address, and the loader
 * keeps one screen for all of them. A route without the argument leaves `screenPath` as
 * ScreenMiddleware set it, the request's path.
 *
 * It is not part of ScreenMiddleware because that one cannot see the route: it sits
 * outside the error middleware and so outside the routing middleware, where nothing is
 * matched yet, and it has to stay there to put `Vary: X-Screen` on an error page. This one
 * is added before addRoutingMiddleware(), i.e. inside it, like RouteNameMiddleware.
 */
final readonly class ScreenAliasMiddleware implements MiddlewareInterface
{
    public function __construct(private Twig $twig) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = RouteContext::fromRequest($request);
        $route = $context->getRoute();
        $screen = $route?->getArgument('screen');
        if ($route !== null && $screen !== null) {
            $this->twig->getEnvironment()->addGlobal('screenPath', self::basePath($context, $route) . $screen);
        }

        return $handler->handle($request);
    }

    /**
     * RouteContext carries the base path only from the route runner on, after every
     * middleware (asked for it before, it throws), so here it is read off the route's own
     * URL: the parser prefixes the base path to a named route's relative URL and to nothing
     * else. Every alias route is named, because url_for() renders the tabs'
     * data-screen-aliases from the names; an unnamed one is taken to have no base path.
     */
    private static function basePath(RouteContext $context, RouteInterface $route): string
    {
        $name = $route->getName();
        if ($name === null) {
            return '';
        }
        $parser = $context->getRouteParser();
        $arguments = $route->getArguments();

        return substr($parser->urlFor($name, $arguments), 0, -\strlen($parser->relativeUrlFor($name, $arguments)));
    }
}
