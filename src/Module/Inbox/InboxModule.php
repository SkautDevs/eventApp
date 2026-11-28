<?php

declare(strict_types=1);

namespace App\Module\Inbox;

use App\Module\Admin\AdminHeadersMiddleware;
use App\Module\Info\ShowAction;
use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * Napiš organizátorům: the Zprávy view of Informace (/informace/zpravy), the form a paired
 * participant writes the organisers a message with, and its send (SendAction). No page and
 * no tab of its own — the view is another address of the Informace screen, drawn by
 * Info\ShowAction, and the Informace tab lists it. The organisers read the messages in a
 * section of the admin page, and mark one handled (HandledAction) or delete it
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
        // One screen, two addresses, as /mapa/plan: `screen` names the screen the view
        // belongs to (ScreenAliasMiddleware). Not on the send: its 403 refusals are pages of
        // their own, and only its refused form names the screen (SendAction)
        $app->get('/informace/zpravy', ShowAction::class)->setName('inbox')
            ->setArgument('view', 'inbox')->setArgument('screen', '/informace');
        $app->post('/informace/zpravy', SendAction::class)->setName('inbox-send');
        $app->post('/admin/inbox/{id:[0-9]+}/handled', HandledAction::class)->setName('admin-inbox-handled')->add(AdminHeadersMiddleware::class);
        $app->post('/admin/inbox/{id:[0-9]+}/delete', DeleteAction::class)->setName('admin-inbox-delete')->add(AdminHeadersMiddleware::class);
    }
}
