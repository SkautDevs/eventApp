<?php

declare(strict_types=1);

namespace App\Module\Map\View;

/**
 * One place with GPS for Google's map: its id and its degrees as `sprintf('%.6F')`, the
 * precision Embed::centred() writes, so a script can centre the embed on it.
 */
final readonly class MapPlace
{
    public function __construct(
        public int $id,
        public string $lat,
        public string $lng,
    ) {}
}
