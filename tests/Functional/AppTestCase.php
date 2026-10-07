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
    /**
     * A browser subscription's keys: a real P-256 public point and a 16-byte auth secret.
     * Public keys are not secrets; the matching private key exists nowhere.
     */
    public const array BROWSER_KEYS = ['p256dh' => 'BLZ65Q8g4oatZNOAPJgP1ME620NAIr0qnOhCw3nt6Nf66VnM3y6br0zfeWRQ1j45DA59SYPkitWrWtx6vApz0DE', 'auth' => 'AVGT4ioSQu2082DMC3bQDA'];

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

        // every app gets throwaway push storage, silent loggers and a spy sender (a welcome
        // must never reach a real push service from a test, and a fake sender needs no
        // VAPID keys); a test that needs to look inside passes its own
        $overrides += [
            \PDO::class => self::memoryDb(),
            \Psr\Log\LoggerInterface::class => new \Psr\Log\NullLogger(),
            Kernel::ERRORS_LOGGER => new \Psr\Log\NullLogger(),
            \App\Push\PushSenderInterface::class => new SpyPushSender(),
        ];

        return Kernel::create($event, $overrides);
    }

    /** An in-memory database in the current schema. Pass it as \PDO::class to look inside. */
    public static function memoryDb(): \PDO
    {
        $pdo = \App\Storage\Database::open(':memory:');
        (new \App\Storage\Migrator())->migrate($pdo);

        return $pdo;
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
