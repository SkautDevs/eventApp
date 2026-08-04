<?php

declare(strict_types=1);

namespace App\Module;

use App\EventConfig;
use Slim\App;
use Slim\Views\Twig;

final class MapModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'map';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Mapa', 'route' => 'map', 'icon' => 'fas fa-map-marked-alt', 'order' => 20];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/mapa', function ($request, $response) {
            $embedUrl = $this->get(EventConfig::class)->get('map')['embedUrl'] ?? null;

            // A config still carrying the REPLACE-ME placeholder would embed a Google 404.
            // Say so instead — the organisers have not published the map yet.
            if ($embedUrl === null || $embedUrl === '' || str_contains($embedUrl, 'REPLACE-ME')) {
                $embedUrl = null;
            }

            return $this->get(Twig::class)->render($response, 'map.twig', ['embedUrl' => $embedUrl]);
        })->setName('map');
    }
}
