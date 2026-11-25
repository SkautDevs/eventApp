<?php

declare(strict_types=1);

namespace App\Module\Profile;

use App\Telemetry\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /profil/propojit/stav: what Profil's script asks every 5 s while the code is shown
 * (www/js/profile/pairing.js). It is always a 200 `{"status": …}` with `PairingCheck`'s
 * status: "pending" (and "slowDown") mean ask again, "approved" means the reader is now
 * logged in, "expired" ends the pairing and leaves the line to Profil.
 */
final class PollPairingAction
{
    public function __construct(
        private readonly PairingCheck $check,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $status = Tracer::span('auth.pairing', 'GET /profil/propojit/stav', fn(): string => $this->check->run());
        $response->getBody()->write((string) json_encode(['status' => $status]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
