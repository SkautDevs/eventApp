<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a write sends the reader once it is done. A plain form POST (no JavaScript, or
 * www/js/shell/write.js falling back after an answer it cannot use) is redirected there.
 * The script sends the form with `X-Write: 1` instead and gets the address as
 * `{"next": "<location>"}`, which it lands on with `location.replace()`: a redirect it
 * followed would leave the POST's own history entry behind the landing.
 */
final class WriteResponse
{
    /** The request header shell/write.js sends with a write. */
    public const string HEADER = 'X-Write';

    public static function redirect(ServerRequestInterface $request, ResponseInterface $response, string $location): ResponseInterface
    {
        if ($request->getHeaderLine(self::HEADER) !== '1') {
            return $response->withHeader('Location', $location)->withStatus(302);
        }
        $response->getBody()->write((string) json_encode(['next' => $location], JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(200);
    }
}
