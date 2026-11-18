<?php

declare(strict_types=1);

namespace App\Module\Shell;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/** GET /offline: the page the service worker answers with when it has neither the network nor a copy. */
final class OfflineAction
{
    public function __construct(private readonly Twig $twig) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->twig->render($response, 'offline.twig');
    }
}
