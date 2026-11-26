<?php

declare(strict_types=1);

namespace App\Program;

/**
 * One place of the event, as kissj's contract 3.1 lists it under `places`
 * (Places::fromKissj() maps it). A place either has both coordinates or neither.
 */
final readonly class Place
{
    /**
     * @param float|null $lat WGS 84 degrees, null with $lng when the place has no GPS
     * @param float|null $lng WGS 84 degrees, null with $lat when the place has no GPS
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?float $lat,
        public ?float $lng,
    ) {}

    public function hasGps(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /**
     * kissj's own row shape, which Places::fromKissj() reads back.
     *
     * @return array{id: int, name: string, lat: ?float, lng: ?float}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'lat' => $this->lat, 'lng' => $this->lng];
    }
}
