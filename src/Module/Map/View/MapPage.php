<?php

declare(strict_types=1);

namespace App\Module\Map\View;

/**
 * Everything templates/map.twig is rendered with, one property per template variable: the
 * Google My Maps embed (null when there is none or it is still the placeholder), the
 * self-hosted plan's web path (null without one) and its alt text. ShowAction renders
 * get_object_vars() of it.
 */
final readonly class MapPage
{
    public function __construct(
        public ?string $embedUrl,
        public ?string $image,
        public string $imageAlt,
    ) {}
}
