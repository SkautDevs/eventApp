<?php

declare(strict_types=1);

namespace App\Http;

use App\EventConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * The Content-Security-Policy, enforced. Scripts are nonced — a fresh nonce per request,
 * handed to the templates as the `cspNonce` global — and nothing inline runs without it.
 * Styles are not: the timeline's per-programme `style` attributes cannot be hashed and
 * cannot move to JavaScript without breaking the no-JS layout, and an inline style can
 * neither run code nor read data. kissj is called by PHP, never by the browser, so it has
 * no place here.
 */
final class CspMiddleware implements MiddlewareInterface
{
    /** In emission order; an empty directive is left out and falls back to default-src. */
    private const array BASE = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        // every font and the icon stylesheet are served from the app itself (www/fonts/, www/vendor/)
        'style-src' => ["'self'", "'unsafe-inline'"],
        'font-src' => ["'self'"],
        'img-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        'media-src' => [],
        'frame-src' => [],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
        'object-src' => ["'none'"],
    ];

    /** @param array<string, list<string>> $extras directive => origins merged into BASE */
    public function __construct(private readonly Twig $twig, private readonly array $extras = [])
    {
    }

    /**
     * What an event adds: its map's origin when the map is on and published, and
     * whatever its config's `csp` key names.
     *
     * @return array<string, list<string>>
     */
    public static function extrasFor(EventConfig $event): array
    {
        $extras = [];

        $embedUrl = $event->get('map')['embedUrl'] ?? null;
        if ($event->isEnabled('map') && is_string($embedUrl) && !str_contains($embedUrl, 'REPLACE-ME')) {
            $mapOrigin = self::origin($embedUrl);
            if ($mapOrigin !== null) {
                $extras['frame-src'][] = $mapOrigin;
            }
        }

        foreach ($event->csp as $directive => $origins) {
            foreach ($origins as $origin) {
                $extras[$directive][] = $origin;
            }
        }

        return $extras;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = bin2hex(random_bytes(16));
        // overwrites the global registered at environment build, as `fragment` does
        $this->twig->getEnvironment()->addGlobal('cspNonce', $nonce);

        return $handler->handle($request)->withHeader('Content-Security-Policy', $this->policy($nonce));
    }

    public function policy(string $nonce): string
    {
        $directives = self::BASE;
        $directives['script-src'][] = "'nonce-" . $nonce . "'";
        foreach ($this->extras as $directive => $origins) {
            $directives[$directive] = array_values(array_unique([...($directives[$directive] ?? []), ...$origins]));
        }

        $parts = [];
        foreach ($directives as $directive => $values) {
            if ($values !== []) {
                $parts[] = $directive . ' ' . implode(' ', $values);
            }
        }

        return implode('; ', $parts);
    }

    /** `https://host[:port]` of an https URL, or null */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
