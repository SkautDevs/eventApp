<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventConfig;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class AppTestCase extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function createApp(string $slug = 'obrok19', array $overrides = []): App
    {
        $event = EventConfig::load($this->eventsDir(), $slug);

        return Kernel::create($event, $overrides);
    }

    protected function eventsDir(): string
    {
        return dirname(__DIR__, 2) . '/events';
    }

    protected function request(App $app, string $method, string $uri, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $app->handle($request);
    }
}
