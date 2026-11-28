<?php

declare(strict_types=1);

namespace App\Module\Inbox;

use App\Module\Admin\AdminHeadersMiddleware;
use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * Napiš organizátorům (/zpravy): the form a paired participant writes the organisers a
 * message with (ShowAction), and its send (SendAction). No tab of its own — the way in is
 * its card on Informace, and the page lights that tab. The organisers read the messages in
 * a section of the admin page, and mark one handled (HandledAction) or delete it
 * (DeleteAction), with the admin headers.
 */
final class InboxModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'inbox';
    }

    public function menuItem(): ?MenuItem
    {
        return null;
    }

    public function script(): string
    {
        return 'inbox/index.js';
    }

    public function stylesheet(): string
    {
        return 'inbox.css';
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->get('/zpravy', ShowAction::class)->setName('inbox');
        $app->post('/zpravy', SendAction::class)->setName('inbox-send');
        $app->post('/admin/inbox/{id:[0-9]+}/handled', HandledAction::class)->setName('admin-inbox-handled')->add(AdminHeadersMiddleware::class);
        $app->post('/admin/inbox/{id:[0-9]+}/delete', DeleteAction::class)->setName('admin-inbox-delete')->add(AdminHeadersMiddleware::class);
    }
}
