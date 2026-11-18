<?php

declare(strict_types=1);

namespace App\Module\Admin;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * The organisers' admin page, /admin/notify: the send form (SendAction), the message log
 * with its hide and show forms (HideAction), and the shared link's login (ShowAction).
 * Part of every event with `push`, but not a feature of its own (ModuleRegistry), and with
 * no tab. Every admin response carries AdminHeadersMiddleware's headers.
 */
final class AdminModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'admin';
    }

    public function menuItem(): ?MenuItem
    {
        return null;
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->get('/admin/notify', ShowAction::class)->setName('admin-notify')->add(AdminHeadersMiddleware::class);
        $app->post('/admin/notify', SendAction::class)->add(AdminHeadersMiddleware::class);
        $app->post('/admin/notify/{id:[0-9]+}/hidden', HideAction::class)->setName('admin-notify-hidden')->add(AdminHeadersMiddleware::class);
    }
}
