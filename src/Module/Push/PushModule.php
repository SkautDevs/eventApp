<?php

declare(strict_types=1);

namespace App\Module\Push;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * Web push: POST /push/subscribe (SubscribeAction) saves a browser's subscription. No tab
 * of its own; the opt-in is the install card's button, and the organisers send from the
 * admin page (AdminModule), which comes with this feature.
 */
final class PushModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'push';
    }

    public function menuItem(): ?MenuItem
    {
        return null;
    }

    public function script(): string
    {
        return 'push/index.js';
    }

    public function stylesheet(): ?string
    {
        return null;
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->post('/push/subscribe', SubscribeAction::class)->setName('push-subscribe');
    }
}
