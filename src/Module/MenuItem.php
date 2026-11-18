<?php

declare(strict_types=1);

namespace App\Module;

/**
 * One tab of the bottom bar, after Domů. `key` is the module's feature key, which a
 * template's `activeTab` is matched against; `route` is the route name the tab links to
 * through url_for(); `icon` is a Font Awesome class; `order` sorts the bar independently
 * of the order the features happen to be listed in the event config (Program 10, Místa 20,
 * Informace 30). The service worker's precache list takes the tabs' routes in the same order.
 */
final readonly class MenuItem
{
    public function __construct(public string $key, public string $label, public string $route, public string $icon, public int $order) {}
}
