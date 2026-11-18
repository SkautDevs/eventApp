<?php

declare(strict_types=1);

namespace App\View;

use Slim\Views\Twig;

/**
 * The instance app's Twig environment: uncached, with only the `cspNonce` global, for
 * the picker and the instance error page. It knows no event.
 */
final readonly class InstanceTwigFactory
{
    public function __construct(private string $root) {}

    public function create(): Twig
    {
        $twig = Twig::create($this->root . '/templates', ['cache' => false]);
        // registered so CspMiddleware may overwrite it per request
        $twig->getEnvironment()->addGlobal('cspNonce', '');

        return $twig;
    }
}
