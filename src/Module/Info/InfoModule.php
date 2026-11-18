<?php

declare(strict_types=1);

namespace App\Module\Info;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * Informace (/informace), the third tab: the event's crisis contact, the notifications the
 * organisers sent, and the quick links. The screen is ShowAction.
 */
final class InfoModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'info';
    }

    public function menuItem(): MenuItem
    {
        return new MenuItem(self::key(), 'Informace', 'info', 'fas fa-info-circle', 30);
    }

    public function script(): ?string
    {
        return null;
    }

    public function stylesheet(): string
    {
        return 'info.css';
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->get('/informace', ShowAction::class)->setName('info');
    }
}
