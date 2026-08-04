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
            return $this->get(Twig::class)->render($response, 'map.twig', [
                'embedUrl' => $this->get(EventConfig::class)->get('map')['embedUrl'] ?? null,
            ]);
        })->setName('map');
    }
}
