<?php

declare(strict_types=1);

namespace App\Module\Home;

use App\EventConfig;
use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * Domů (`GET /`), part of every event and the first tab: the event's short name, with its
 * menu logo or the home icon (the layout draws that tab itself).
 */
final class HomeModule implements ModuleInterface
{
    public function __construct(private readonly EventConfig $event) {}

    public static function key(): string
    {
        return 'homepage';
    }

    public function menuItem(): MenuItem
    {
        return new MenuItem('homepage', $this->event->get('shortName') ?? $this->event->name, 'homepage', 'fas fa-home', 0);
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->get('/', ShowAction::class)->setName('homepage');
    }
}
