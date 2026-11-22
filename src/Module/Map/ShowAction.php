<?php

declare(strict_types=1);

namespace App\Module\Map;

use App\EventConfig;
use App\Module\Map\View\MapPage;
use App\View\TemplateContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * GET /mapa and /mapa/plan: the view the route's `view` argument names, painted first,
 * unless the event has only the other pane, which then shows on either address; and the
 * event config's `map` — the Google My Maps embed (`embedUrl`, none while it still
 * carries the REPLACE-ME placeholder), the self-hosted site plan (`image`, a path under
 * www/) and its alt text (`imageAlt`, default `Plán areálu`).
 */
final class ShowAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly Twig $twig,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $map = $this->event->map;
        $embedUrl = $map?->embedUrl;

        // A config still carrying the REPLACE-ME placeholder would embed a Google 404.
        // Say so instead — the organisers have not published the map yet.
        if ($embedUrl === null || $embedUrl === '' || str_contains($embedUrl, 'REPLACE-ME')) {
            $embedUrl = null;
        }
        // the handbook's own drawing, self-hosted so it works offline; an <img>, so an
        // SVG is shown and never run
        $image = $map?->image;
        $image = $image !== null && $image !== '' ? '/' . ltrim($image, '/') : null;
        $imageAlt = $map?->imageAlt;
        $imageAlt = $imageAlt !== null && $imageAlt !== '' ? $imageAlt : 'Plán areálu';

        // the requested view, or the only pane there is: /mapa/plan with no plan shows the map
        $view = ($args['view'] ?? 'google') === 'plan' ? 'plan' : 'google';
        if ($image === null && $embedUrl !== null) {
            $view = 'google';
        } elseif ($embedUrl === null && $image !== null) {
            $view = 'plan';
        }

        return $this->twig->render($response, 'map.twig', TemplateContext::of(new MapPage($view, $embedUrl, $image, $imageAlt)));
    }
}
