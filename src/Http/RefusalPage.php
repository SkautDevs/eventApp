<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;

/**
 * A refused form as a page of the app (error.twig): a reader who hits it (a form left
 * open across a deploy, a privacy extension stripping the headers) is told what to do and
 * given the way back, with the 403 kept.
 */
final class RefusalPage
{
    public function __construct(private readonly Twig $twig) {}

    public function render(ResponseInterface $response, string $message, string $backHref, string $backLabel): ResponseInterface
    {
        return $this->twig->render($response->withStatus(403)->withHeader('Content-Type', 'text/html; charset=utf-8'), 'error.twig', [
            'notFound' => false,
            'details' => null,
            'message' => $message,
            'back' => ['href' => $backHref, 'label' => $backLabel],
        ]);
    }
}
