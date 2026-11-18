<?php

declare(strict_types=1);

namespace App\Module\Profile;

use App\Auth\Authenticator;
use App\Auth\PairingState;
use App\Http\RefusalPage;
use App\Http\SameOrigin;
use App\Program\ProgramProviderInterface;
use App\Program\SessionExpiredException;
use App\Telemetry\Collector;
use App\Toast\Toasts;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * POST /profil/odpojit, the logout: kissj is asked to end the device session (best effort:
 * a reader is logged out of the app whatever kissj says), then the identity is dropped.
 * `SameOrigin` (else a 403 page), `revokeSession()` at kissj best effort (a
 * `SessionExpiredException` counts as done, anything else is collected), the identity
 * dropped, and the `Odhlášeno` / `Obsah teď můžeš jen prohlížet` toast — only when
 * somebody was logged in.
 */
final class RevokeAction
{
    /** The 403 page's message for a cross-site logout. */
    private const string REFUSED = 'Odhlášení se nepodařilo. Načti stránku a zkus to znovu.';

    public function __construct(
        private readonly RefusalPage $refusal,
        private readonly Authenticator $auth,
        private readonly ProgramProviderInterface $provider,
        private readonly PairingState $pairingState,
        private readonly Toasts $toasts,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        if (!SameOrigin::allows($request)) {
            return $this->refusal->render($response, self::REFUSED, $base . '/profil', 'Zpět na profil');
        }
        $auth = $this->auth;
        $identity = $auth->identity();
        if ($identity !== null) {
            try {
                $this->provider->revokeSession($identity->sessionToken);
            } catch (SessionExpiredException) {
                // kissj no longer knew the session: ended already, which is what was asked
            } catch (\Throwable $e) {
                Collector::collect($e);
            }
        }
        $auth->logout();
        $this->pairingState->drop();
        // "Odhlášeno" only says something to a reader who was logged in
        if ($identity !== null) {
            $this->toasts->loggedOut();
        }

        return $response->withHeader('Location', $base . '/profil')->withStatus(302);
    }
}
