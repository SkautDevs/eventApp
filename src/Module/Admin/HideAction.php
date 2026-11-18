<?php

declare(strict_types=1);

namespace App\Module\Admin;

use App\EventConfig;
use App\Http\FormField;
use App\Push\MessageRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * POST /admin/notify/{id}/hidden: hides a sent message from the readers (`hidden=1`) or
 * shows it again, recording who (Podpis) and when, then redirects back to the log page the
 * form was on. Nothing is ever deleted; an unknown message is a bare 404.
 */
final class HideAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly AdminSession $admin,
        private readonly AdminPageRenderer $page,
        private readonly MessageRepository $messages,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!$this->admin->isOpen() || !$this->admin->csrfMatches($body['csrf'] ?? null)) {
            return $this->page->refused($request->withParsedBody([]), $response);
        }
        $signature = trim(FormField::text($body, 'signature'));
        if (!AdminPageRenderer::signatureValid($signature)) {
            return $this->page->render($request, $response->withStatus(422), errors: [AdminPageRenderer::SIGNATURE_ERROR]);
        }

        $changed = $this->messages->setHidden(
            $this->event->slug,
            (int) $args['id'],
            FormField::text($body, 'hidden') === '1',
            $signature,
        );
        if (!$changed) {
            return $response->withStatus(404);
        }

        $location = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin-notify', [], [
            'page' => (string) max(1, FormField::int($body, 'page', 1)),
        ]);

        return $response->withHeader('Location', $location)->withStatus(303);
    }
}
