<?php

declare(strict_types=1);

namespace App\Module\Info;

use App\EventConfig;
use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * Informace (/informace), the third tab: the event's crisis contact, the notifications the
 * organisers sent, and the quick links. The screen is ShowAction. An event with `inbox`
 * gives the screen a second view, Zprávy (/informace/zpravy, registered by InboxModule),
 * which the tab lists as its other address.
 */
final class InfoModule implements ModuleInterface
{
    public function __construct(private readonly EventConfig $event) {}

    public static function key(): string
    {
        return 'info';
    }

    public function menuItem(): MenuItem
    {
        return new MenuItem(self::key(), 'Informace', 'info', 'fas fa-info-circle', 30, $this->event->isEnabled('inbox') ? ['inbox'] : []);
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
        $app->get('/informace', ShowAction::class)->setName('info')
            ->setArgument('view', 'info');
    }
}
