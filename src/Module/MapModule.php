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
            $map = $this->get(EventConfig::class)->get('map') ?? [];
            $map = is_array($map) ? $map : [];
            $embedUrl = is_string($map['embedUrl'] ?? null) ? $map['embedUrl'] : null;

            // A config still carrying the REPLACE-ME placeholder would embed a Google 404.
            // Say so instead — the organisers have not published the map yet.
            if ($embedUrl === null || $embedUrl === '' || str_contains($embedUrl, 'REPLACE-ME')) {
                $embedUrl = null;
            }
            // the handbook's own drawing, self-hosted so it works offline; an <img>, so an
            // SVG is shown and never run
            $image = is_string($map['image'] ?? null) && $map['image'] !== '' ? '/' . ltrim($map['image'], '/') : null;
            $imageAlt = is_string($map['imageAlt'] ?? null) && $map['imageAlt'] !== '' ? $map['imageAlt'] : 'Plán areálu';

            return $this->get(Twig::class)->render($response, 'map.twig', [
                'embedUrl' => $embedUrl,
                'image' => $image,
                'imageAlt' => $imageAlt,
            ]);
        })->setName('map');
    }
}
