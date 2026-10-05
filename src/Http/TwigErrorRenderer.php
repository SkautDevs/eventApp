<?php

declare(strict_types=1);

namespace App\Http;

use App\Telemetry\Collector;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorRendererInterface;
use Slim\Views\Twig;

/**
 * Error pages as pages of the app: Slim still picks the status code and the content type,
 * this renders the HTML. The event app's template extends the layout, so a 404 wears the
 * event and keeps the tab bar; the instance app's is plain. A fragment request that errors
 * gets the whole document too — app.js treats any non-200 as "not a screen".
 */
final class TwigErrorRenderer implements ErrorRendererInterface
{
    /** What the reader gets when the error page itself cannot be rendered. */
    public const FALLBACK = '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Chyba</title></head><body><p>Něco se pokazilo. Zkus to za chvíli.</p></body></html>';

    public function __construct(private readonly Twig $twig, private readonly string $template)
    {
    }

    public function __invoke(\Throwable $exception, bool $displayErrorDetails): string
    {
        try {
            return $this->twig->fetch($this->template, [
                'notFound' => $exception instanceof HttpNotFoundException || $exception instanceof HttpMethodNotAllowedException,
                'details' => $displayErrorDetails ? self::details($exception) : null,
            ]);
        } catch (\Throwable $renderFailure) {
            // A broken template or a session that will not start: the reader still gets a
            // page that says what happened, and the failure is a bug of its own.
            Collector::collect($renderFailure);

            return self::FALLBACK;
        }
    }

    /** Slim's detail set — type, code, message, file, line, trace — for every exception in the chain. */
    public static function details(\Throwable $exception): string
    {
        $parts = [];
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $parts[] = sprintf(
                "%s (code %s): %s\n%s:%d\n%s",
                $e::class,
                (string) $e->getCode(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            );
        }

        return implode("\n\nPrevious:\n", $parts);
    }
}
