<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Auth\Authenticator;
use App\Http\RefusalPage;
use App\Http\SameOrigin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * POST /programy/{id}/odhlasit, the sheet's unregister form. A cross-site form or a reader
 * who is not paired gets a page of its own (403); otherwise RegistrationWriter writes and
 * leaves the toast, and the reader is sent back to the programme's deep link.
 */
final class UnregisterAction
{
    /** The 403 page's message for a cross-site form. */
    private const string CROSS_SITE = 'Odhlášení z programu se nepodařilo. Načti stránku a zkus to znovu.';

    public function __construct(
        private readonly RefusalPage $refusal,
        private readonly Authenticator $auth,
        private readonly RegistrationWriter $writer,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        // a cross-site form must not unregister the reader from anything
        if (!SameOrigin::allows($request)) {
            return $this->refusal->render($response, self::CROSS_SITE, $base . '/programy', 'Zpět na program');
        }
        if ($this->auth->identity() === null) {
            return $this->refusal->render($response, RegistrationWriter::NOT_PAIRED . '.', $base . '/profil', 'Přihlásit se');
        }

        return $response
            ->withHeader('Location', $this->writer->write(false, (int) $args['id']))
            ->withStatus(302);
    }
}
