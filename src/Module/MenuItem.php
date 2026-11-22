<?php

declare(strict_types=1);

namespace App\Module;

/**
 * One tab of the bottom bar, after Domů. `key` is the module's feature key, which a
 * template's `activeTab` is matched against; `route` is the route name the tab links to
 * through url_for(); `icon` is a Font Awesome class; `order` sorts the bar independently
 * of the order the features happen to be listed in the event config (Program 10, Místa 20,
 * Informace 30). The service worker's precache list takes the tabs' routes in the same order.
 * `aliases` are route names too: their URLs are this tab's other addresses, the views of
 * its screen that have a path of their own (Program's /programy/prehled and
 * /programy/moje). The tab renders them as `data-screen-aliases`, in this order.
 */
final readonly class MenuItem
{
    /** @param list<string> $aliases route names */
    public function __construct(
        public string $key,
        public string $label,
        public string $route,
        public string $icon,
        public int $order,
        public array $aliases = [],
    ) {}
}
