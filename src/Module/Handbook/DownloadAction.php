<?php

declare(strict_types=1);

namespace App\Module\Handbook;

use App\EventConfig;
use App\Paths;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * GET /handbook/download: the event config's `handbook.file` (a path under www/) as an
 * attachment named `handbook.downloadName`, or a bare 404 while the file is missing.
 */
final class DownloadAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly Paths $paths,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $handbook = $this->event->get('handbook');
        $file = $this->paths->www . '/' . $handbook['file'];
        if (!is_file($file)) {
            return $response->withStatus(404);
        }

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment;filename="' . $handbook['downloadName'] . '"')
            ->withBody(new Stream(fopen($file, 'rb')));
    }
}
