<?php

declare(strict_types=1);

namespace App\Module\Map;

/**
 * Google My Maps' embed address, centred on a point: `ll` is the centre and `z=18` a
 * zoom that shows a site's paths. www/js/map/ builds the same string.
 */
final class Embed
{
    public static function centred(string $embedUrl, float $lat, float $lng): string
    {
        return $embedUrl . (str_contains($embedUrl, '?') ? '&' : '?') . sprintf('ll=%.6F,%.6F&z=18', $lat, $lng);
    }
}
