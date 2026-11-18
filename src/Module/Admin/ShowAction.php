<?php

declare(strict_types=1);

namespace App\Module\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * GET /admin/notify: the shared link with `?token=` logs the browser in for a day and
 * redirects to the bare URL, so the token never sits in history, a referrer or a Sentry
 * transaction; a wrong token is a bare 403. Without the token, a logged-in browser gets
 * the admin page and any other a bare 403.
 */
final class ShowAction
{
    public function __construct(
        private readonly AdminSession $admin,
        private readonly AdminPage $page,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $query = $request->getQueryParams();
        if (array_key_exists('token', $query)) {
            if (!$this->admin->open(is_string($query['token']) ? $query['token'] : '')) {
                return $response->withStatus(403);
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin-notify'))
                ->withStatus(303);
        }
        if (!$this->admin->isOpen()) {
            return $response->withStatus(403);
        }

        return $this->page->render($request, $response);
    }
}
