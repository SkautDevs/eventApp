<?php

declare(strict_types=1);

namespace App\Module\Inbox;

use App\Auth\Authenticator;
use App\Inbox\Submission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /zpravy: the empty form for a paired participant, signed with their nickname (or their
 * participant number without one), or the login card for anyone else (FormPage). The
 * identity comes from the session alone; the page never calls kissj.
 */
final class ShowAction
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly FormPage $form,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->form->render($response, $this->auth->identity(), Submission::empty(), []);
    }
}
