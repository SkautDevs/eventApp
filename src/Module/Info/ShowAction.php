<?php

declare(strict_types=1);

namespace App\Module\Info;

use App\Inbox\Submission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /informace and, with `inbox`, /informace/zpravy: the one Informace screen
 * (InfoScreen), painted on the view the route's `view` argument names, with the Zprávy
 * view's form empty.
 */
final class ShowAction
{
    public function __construct(private readonly InfoScreen $screen) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $view = ($args['view'] ?? 'info') === 'inbox' ? 'inbox' : 'info';

        return $this->screen->render($request, $response, $view, Submission::empty(), []);
    }
}
