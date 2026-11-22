<?php

declare(strict_types=1);

namespace App\Module\Map\View;

/**
 * Everything templates/map.twig is rendered with, one property per template variable: the
 * view painted first (`google` or `plan`: the one the address names, or the only pane the
 * event has), the Google My Maps embed (null when there is none or it is still the
 * placeholder), the self-hosted plan's web path (null without one) and its alt text.
 * ShowAction renders get_object_vars() of it.
 */
final readonly class MapPage
{
    /** @param 'google'|'plan' $view */
    public function __construct(
        public string $view,
        public ?string $embedUrl,
        public ?string $image,
        public string $imageAlt,
    ) {}
}
