<?php

declare(strict_types=1);

namespace App\Module;

use App\EventConfig;
use Slim\App;
use Slim\Psr7\Stream;

final class HandbookModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'handbook';
    }

    public function menuItem(): ?array
    {
        // no tab of its own — the handbook is the first entry on the Odkazy screen
        return null;
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/handbook/download', function ($request, $response) {
            $handbook = $this->get(EventConfig::class)->get('handbook');
            $file = dirname(__DIR__, 2) . '/www/' . $handbook['file'];
            if (!is_file($file)) {
                return $response->withStatus(404);
            }

            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment;filename="' . $handbook['downloadName'] . '"')
                ->withBody(new Stream(fopen($file, 'rb')));
        })->setName('handbook-download');
    }
}
