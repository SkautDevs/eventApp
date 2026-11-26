<?php

declare(strict_types=1);

namespace App\Module\Map;

use App\EventConfig;
use App\Module\Map\View\MapPage;
use App\Module\Map\View\MapPlace;
use App\Module\Map\View\PlanMarker;
use App\Program\Place;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\ProgramUnavailableException;
use App\View\TemplateContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * GET /mapa and /mapa/plan: the view the route's `view` argument names, painted first,
 * unless the event has only the other pane, which then shows on either address; and the
 * event config's `map` — the Google My Maps embed (`embedUrl`, none while it still
 * carries the REPLACE-ME placeholder), the self-hosted site plan (`image`, a path under
 * www/), its alt text (`imageAlt`, default `Plán areálu`) and its edges (`planBounds`).
 *
 * The places come from the programme provider, and only for an event with the `programs`
 * feature, since only Program links to a place: a marker on the plan for each place with
 * GPS inside planBounds (hidden), and the list of places with GPS beside Google's map. A
 * provider that fails leaves the map without either. `?misto=<id>` names a place: a known
 * one with GPS shows its marker, centres Google's map on it and is kept by both pills;
 * anything else is ignored.
 */
final class ShowAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly Twig $twig,
        private readonly ProgramProviderInterface $provider,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $map = $this->event->map;
        $embedUrl = $map?->usableEmbedUrl();
        // the handbook's own drawing, self-hosted so it works offline; an <img>, so an
        // SVG is shown and never run
        $image = $map?->image;
        $image = $image !== null && $image !== '' ? '/' . ltrim($image, '/') : null;
        $imageAlt = $map?->imageAlt;
        $imageAlt = $imageAlt !== null && $imageAlt !== '' ? $imageAlt : 'Plán areálu';
        $bounds = $image !== null ? $map?->planBounds : null;

        // the requested view, or the only pane there is: /mapa/plan with no plan shows the map
        $view = ($args['view'] ?? 'google') === 'plan' ? 'plan' : 'google';
        if ($image === null && $embedUrl !== null) {
            $view = 'google';
        } elseif ($embedUrl === null && $image !== null) {
            $view = 'plan';
        }

        $places = $this->event->isEnabled('programs') && ($bounds !== null || $embedUrl !== null) ? $this->placesWithGps() : [];
        $misto = self::misto($request->getQueryParams()['misto'] ?? null, $places);

        // the plan's markers: each place on it, at its share of the image, the named one shown
        $markers = [];
        foreach ($bounds === null ? [] : $places as [$place, $lat, $lng]) {
            if ($bounds?->contains($lat, $lng) === true) {
                $markers[] = new PlanMarker($place->id, $place->name, sprintf('%.2F', $bounds->x($lng)), sprintf('%.2F', $bounds->y($lat)), $place->id === $misto);
            }
        }
        // Google's map: every place with GPS, on the plan or off it, and the named one centred
        $mapPlaces = [];
        $embedSrc = $embedUrl;
        if ($embedUrl !== null) {
            foreach ($places as [$place, $lat, $lng]) {
                $mapPlaces[] = new MapPlace($place->id, sprintf('%.6F', $lat), sprintf('%.6F', $lng));
            }
            if ($misto !== null) {
                [, $lat, $lng] = $places[$misto];
                $embedSrc = Embed::centred($embedUrl, $lat, $lng);
            }
        }

        return $this->twig->render($response, 'map.twig', TemplateContext::of(
            new MapPage($view, $embedUrl, $image, $imageAlt, $markers, $mapPlaces, $misto, $embedSrc),
        ));
    }

    /**
     * The provider's places that have GPS, each with its two degrees, keyed by id in the
     * provider's order; none when the provider fails, so the map still renders.
     *
     * @return array<int, array{Place, float, float}>
     */
    private function placesWithGps(): array
    {
        try {
            $places = $this->provider->getPlaces();
        } catch (ProgramUnavailableException|ProgramDataException) {
            return [];
        }

        $withGps = [];
        foreach ($places as $id => $place) {
            if ($place->lat !== null && $place->lng !== null) {
                $withGps[$id] = [$place, $place->lat, $place->lng];
            }
        }

        return $withGps;
    }

    /**
     * The `misto` query value as a place id: a string of one to nine digits without a
     * leading zero that names one of $places; null for anything else.
     *
     * @param array<int, mixed> $places
     */
    private static function misto(mixed $raw, array $places): ?int
    {
        if (!is_string($raw) || preg_match('/\A[1-9][0-9]{0,8}\z/', $raw) !== 1) {
            return null;
        }

        return isset($places[(int) $raw]) ? (int) $raw : null;
    }
}
