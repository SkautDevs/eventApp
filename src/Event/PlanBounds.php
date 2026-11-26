<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's `map.planBounds`: the WGS 84 degrees of the plan image's four edges, north up
 * and drawn without rotation, so a place's share of the image's width and height is its
 * share of the span between the edges. Místa puts a marker there.
 */
final readonly class PlanBounds
{
    private function __construct(
        public float $north,
        public float $south,
        public float $west,
        public float $east,
    ) {}

    /**
     * The config's `planBounds` map: four numbers (`north`, `south`, `west`, `east`), the
     * latitudes in [−90, 90], the longitudes in [−180, 180], north above south and east
     * right of west. Anything else is null; EventConfig refuses to boot on it.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }
        $degrees = [];
        foreach (['north' => 90, 'south' => 90, 'west' => 180, 'east' => 180] as $edge => $limit) {
            $value = $raw[$edge] ?? null;
            if (!is_int($value) && !is_float($value) || $value < -$limit || $value > $limit) {
                return null;
            }
            $degrees[$edge] = (float) $value;
        }
        if ($degrees['north'] <= $degrees['south'] || $degrees['east'] <= $degrees['west']) {
            return null;
        }

        return new self($degrees['north'], $degrees['south'], $degrees['west'], $degrees['east']);
    }

    /** Whether the point lies on the plan; the edges count as on it. */
    public function contains(float $lat, float $lng): bool
    {
        return $lat >= $this->south && $lat <= $this->north && $lng >= $this->west && $lng <= $this->east;
    }

    /** The longitude's distance from the plan's left edge, in % of its width. */
    public function x(float $lng): float
    {
        return ($lng - $this->west) / ($this->east - $this->west) * 100;
    }

    /** The latitude's distance from the plan's top edge, in % of its height. */
    public function y(float $lat): float
    {
        return ($this->north - $lat) / ($this->north - $this->south) * 100;
    }
}
