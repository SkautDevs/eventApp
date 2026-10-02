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

    protected function createApp(string $slug = 'obrok19', array $overrides = [], bool $fixtureEvent = false): App
    {
        $dir = $fixtureEvent
            ? dirname(__DIR__) . '/fixtures/events'
            : $this->eventsDir();
        $event = EventConfig::load($dir, $slug);

        // every app gets throwaway push storage; a test that needs to look inside passes its own
        $overrides += [
            \App\Push\SubscriptionRepository::class => new \App\Push\SubscriptionRepository(':memory:'),
            \App\Push\MessageRepository::class => new \App\Push\MessageRepository(':memory:'),
        ];

        return Kernel::create($event, $overrides);
    }

    protected function eventsDir(): string
    {
        return dirname(__DIR__, 2) . '/events';
    }

    /**
     * A request to a path *inside* the event: '/programy' goes to '/obrok19/programy'.
     * Tests keep reading like the routes they exercise.
     *
     * @param array<string, string> $headers
     */
    protected function request(App $app, string $method, string $uri, ?array $body = null, array $headers = []): ResponseInterface
    {
        return $this->rawRequest($app, $method, $app->getBasePath() . $uri, $body, $headers);
    }

    /** @param array<string, string> $headers */
    protected function rawRequest(App $app, string $method, string $uri, ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }
}
