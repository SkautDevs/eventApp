<?php

declare(strict_types=1);

namespace App\Module\Handbook;

use App\Module\MenuItem;
use App\Module\ModuleInterface;
use Slim\App;

/** The event's handbook PDF (/handbook/download, DownloadAction). No tab of its own. */
final class HandbookModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'handbook';
    }

    public function menuItem(): ?MenuItem
    {
        // no tab of its own — the handbook is the first of the quick links on Informace
        return null;
    }

    public function script(): ?string
    {
        return null;
    }

    public function stylesheet(): ?string
    {
        return null;
    }

    /** @param App<\Psr\Container\ContainerInterface|null> $app */
    public function registerRoutes(App $app): void
    {
        $app->get('/handbook/download', DownloadAction::class)->setName('handbook-download');
    }
}
