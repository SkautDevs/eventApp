<?php

declare(strict_types=1);

namespace App\Module\Profile;

use App\Auth\Authenticator;
use App\Auth\IdentityView;
use App\Auth\PairingState;
use App\Module\Profile\View\PairingView;
use App\Module\Profile\View\ProfilePage;
use App\Toast\Toasts;
use App\View\TemplateContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * GET /profil: who is paired, or the pairing in progress and how the last one ended, and
 * the login's or the logout's toast, once. Logged out, `?program=<id>` (a sheet's login
 * link) goes into the start form's hidden `program` field, digits only and not checked
 * against the provider (the start checks it); pending, the field of `Začít znovu` carries
 * the pairing's own return target on, so a restart keeps it.
 */
final class ShowAction
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly PairingState $pairingState,
        private readonly Toasts $toasts,
        private readonly Twig $twig,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $identity = $this->auth->identity();
        $pairing = $identity === null ? $this->pairingState->pending() : null;
        // a login's or a logout's, once
        $toast = $this->toasts->take($request);

        $page = new ProfilePage(
            identity: $identity === null ? null : IdentityView::of($identity),
            pairing: $pairing === null ? null : new PairingView($pairing->formattedUserCode(), $pairing->verificationUrl, $this->pairingState->waitingLine()),
            // the start's, the poll's or a session's outcome, on every read while it lasts
            outcome: $this->pairingState->outcomeLine(),
            toast: $toast,
            returnProgram: match (true) {
                $identity !== null => null,
                $pairing !== null => $this->pairingState->returnProgram(),
                default => PairingState::programId($request->getQueryParams()['program'] ?? null),
            },
        );

        return $this->toasts->mark($this->twig->render($response, 'profile.twig', TemplateContext::of($page)), $toast);
    }
}
