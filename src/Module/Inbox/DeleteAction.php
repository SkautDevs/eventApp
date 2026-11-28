<?php

declare(strict_types=1);

namespace App\Module\Inbox;

use App\EventConfig;
use App\Http\FormField;
use App\Inbox\InboxRepository;
use App\Module\Admin\AdminPageRenderer;
use App\Module\Admin\AdminSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

/**
 * POST /admin/inbox/{id}/delete: deletes a participant's message for good, then redirects
 * back to the admin page's inbox section. The app log gets one line with the event, the id
 * and the Podpis, never the text or the contact. An unknown message, or one of another
 * event, is a bare 404.
 */
final class DeleteAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly AdminSession $admin,
        private readonly AdminPageRenderer $page,
        private readonly InboxRepository $inbox,
        private readonly LoggerInterface $logger,
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

        $id = (int) $args['id'];
        if (!$this->inbox->delete($this->event->slug, $id)) {
            return $response->withStatus(404);
        }
        $this->logger->info('inbox.deleted', ['event' => $this->event->slug, 'id' => $id, 'by' => $signature]);

        $location = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin-notify', [], [
            'page' => (string) max(1, FormField::int($body, 'page', 1)),
            'inbox' => (string) max(1, FormField::int($body, 'inbox', 1)),
        ]);

        return $response->withHeader('Location', $location . '#inbox')->withStatus(303);
    }
}
