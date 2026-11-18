<?php

declare(strict_types=1);

namespace App\Module\Shell;

use App\EventConfig;
use App\Http\AssetVersion;
use App\Http\Precache;
use App\Module\Modules;
use App\Paths;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * GET /precache.json: what the service worker keeps (Http\Precache). no-cache: the worker
 * must see a new list the moment an asset changes.
 */
final class PrecacheAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly AssetVersion $versions,
        private readonly Modules $modules,
        private readonly Paths $paths,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = Precache::build(
            $this->event,
            RouteContext::fromRequest($request)->getRouteParser(),
            $this->versions,
            $this->modules->menu(),
            $this->paths->www,
        );
        $response->getBody()->write((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-cache');
    }
}
