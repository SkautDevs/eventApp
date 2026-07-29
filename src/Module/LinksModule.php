<?php

declare(strict_types=1);

namespace App\Module;

use App\EventConfig;
use Slim\App;
use Slim\Views\Twig;

final class LinksModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'links';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Odkazy', 'route' => 'links'];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/odkazy', function ($request, $response) {
            return $this->get(Twig::class)->render($response, 'links.twig', [
                'links' => $this->get(EventConfig::class)->content('links'),
            ]);
        })->setName('links');
    }
}
