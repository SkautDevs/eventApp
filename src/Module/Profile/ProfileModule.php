<?php

declare(strict_types=1);

namespace App\Module\Profile;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/**
 * Profil and the login, part of every event: the screen (`GET /profil`, the last tab), and
 * kissj's device pairing — the start, the poll, the no-JS check and the logout.
 */
final class ProfileModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'profile';
    }

    public function menuItem(): MenuItem
    {
        return new MenuItem('profile', 'Profil', 'profile', 'fas fa-user-circle', 40);
    }

    public function script(): string
    {
        return 'profile/index.js';
    }

    public function stylesheet(): string
    {
        return 'profile.css';
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->get('/profil', ShowAction::class)->setName('profile');
        $app->post('/profil/propojit', StartPairingAction::class)->setName('pairing-start');
        $app->get('/profil/propojit/stav', PollPairingAction::class)->setName('pairing-poll');
        $app->post('/profil/kontrola', CheckPairingAction::class)->setName('pairing-check');
        $app->post('/profil/odpojit', RevokeAction::class)->setName('pairing-revoke');
    }
}
