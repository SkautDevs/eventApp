<?php

declare(strict_types=1);

namespace App\Module\Map;

use App\Event\PlanBounds;
use App\EventConfig;
use App\Program\Place;

/**
 * Which Místa address shows a place (Plan call 10). Only a place with GPS has one: Plán
 * (`map-plan`) when the event's plan has `planBounds` and the place lies inside them, the
 * edges included; otherwise Mapa (`map`) when the event has Google's map; otherwise none,
 * since a plan-only event cannot show a place off its plan.
 */
final readonly class PlaceLinks
{
    public function __construct(private EventConfig $event) {}

    /** Whether any place could have an address: Místa is on, with a plan that has bounds or with Google's map. */
    public function possible(): bool
    {
        $map = $this->event->map;
        if ($map === null || !$this->event->isEnabled('map')) {
            return false;
        }

        return $this->plan() !== null || $map->usableEmbedUrl() !== null;
    }

    /** @return 'map-plan'|'map'|null the route that shows $place, null for none */
    public function route(Place $place): ?string
    {
        if ($place->lat === null || $place->lng === null || !$this->possible()) {
            return null;
        }
        if ($this->plan()?->contains($place->lat, $place->lng) === true) {
            return 'map-plan';
        }

        return $this->event->map?->usableEmbedUrl() !== null ? 'map' : null;
    }

    /** The plan's bounds, when the event has a plan image to put a place on. */
    private function plan(): ?PlanBounds
    {
        $map = $this->event->map;

        return $map !== null && $map->image !== null && $map->image !== '' ? $map->planBounds : null;
    }
}
