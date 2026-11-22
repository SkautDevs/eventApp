<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Auth\Authenticator;
use App\Http\FormField;
use App\Http\RefusalPage;
use App\Http\SameOrigin;
use App\Http\WriteResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * POST /programy/{id}/prihlasit, the sheet's register form. A cross-site form or a reader
 * who is not paired gets a page of its own (403); otherwise RegistrationWriter writes and
 * leaves the toast, and the reader is sent back to the programme's deep link on the view
 * the form's `pohled` field names (WriteResponse: a redirect, or `{"next"}` for the script).
 */
final class RegisterAction
{
    /** The 403 page's message for a cross-site form. */
    private const string CROSS_SITE = 'Zápis na program se nepodařil. Načti stránku a zkus to znovu.';

    public function __construct(
        private readonly RefusalPage $refusal,
        private readonly Authenticator $auth,
        private readonly RegistrationWriter $writer,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        // a cross-site form must not register the reader for anything
        if (!SameOrigin::allows($request)) {
            return $this->refusal->render($response, self::CROSS_SITE, $base . '/programy', 'Zpět na program');
        }
        if ($this->auth->identity() === null) {
            return $this->refusal->render($response, RegistrationWriter::NOT_PAIRED . '.', $base . '/profil', 'Přihlásit se');
        }

        return WriteResponse::redirect($request, $response, $this->writer->write(true, (int) $args['id'], FormField::text((array) $request->getParsedBody(), 'pohled')));
    }
}
