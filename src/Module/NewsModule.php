<?php

declare(strict_types=1);

namespace App\Module;

use App\EventConfig;
use Slim\App;
use Slim\Views\Twig;

final class NewsModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'news';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Novinky', 'route' => 'news'];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/novinky', function ($request, $response) {
            return $this->get(Twig::class)->render($response, 'news.twig', [
                'items' => $this->get(EventConfig::class)->content('news'),
            ]);
        })->setName('news');
    }
}
