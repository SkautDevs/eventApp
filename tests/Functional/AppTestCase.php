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

    /**
     * The local programme source's STUB_STATE_PATH for this test: a fresh directory, so a
     * registration or a revoked session never outlives the test, and no test writes var/stub.
     */
    protected string $stubState;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->stubState = sys_get_temp_dir() . '/eventapp-stub-' . bin2hex(random_bytes(6));
        if (!mkdir($this->stubState, 0o700)) {
            throw new \RuntimeException('Could not create the stub state directory ' . $this->stubState);
        }
        $_ENV['STUB_STATE_PATH'] = $this->stubState;
    }

    protected function tearDown(): void
    {
        unset($_ENV['STUB_STATE_PATH']);
        array_map('unlink', glob($this->stubState . '/*') ?: []);
        @rmdir($this->stubState);
        parent::tearDown();
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

    /**
     * Pairs the test's session the way a reader does, through the routes: the start, then
     * one poll, which the local source approves at once.
     */
    protected function pair(App $app): void
    {
        self::assertSame(302, $this->request($app, 'POST', '/profil/propojit')->getStatusCode(), 'the pairing start redirects to Profil');
        $poll = $this->request($app, 'GET', '/profil/propojit/stav');
        self::assertSame(['status' => 'approved'], json_decode((string) $poll->getBody(), true), 'the first poll approves');
    }

    /**
     * Stores an identity directly, without any pairing: for tests of programmes and provider
     * failures, whose providers may not answer a pairing at all. The token has the local
     * source's shape, so the stub accepts it for $participantId.
     */
    protected function loginAs(App $app, int $participantId = 1, ?string $nickname = null): void
    {
        $app->getContainer()->get(\App\Auth\Authenticator::class)->store(
            new \App\Auth\Identity($participantId, $nickname, 'stub-' . $participantId . '-0000000000000000'),
        );
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
