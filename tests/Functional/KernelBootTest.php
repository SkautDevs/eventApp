<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventConfig;
use App\Kernel;
use App\Push\PushSenderInterface;
use Slim\App;

/** A misconfigured push instance fails on its first request, not on the first tap of the button. */
final class KernelBootTest extends AppTestCase
{
    private const array VAPID = ['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT'];

    /** @var array<string, mixed> the per-run pair tests/bootstrap.php put there */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::VAPID as $name) {
            $this->saved[$name] = $_ENV[$name] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        parent::tearDown();
    }

    private function withoutKeys(): void
    {
        foreach (self::VAPID as $name) {
            $_ENV[$name] = '';
        }
    }

    public function testAPushEventWithoutKeysFailsAtBoot(): void
    {
        $this->withoutKeys();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VAPID_PUBLIC_KEY');
        Kernel::create(EventConfig::load($this->eventsDir(), 'obrok27'), [\PDO::class => self::memoryDb()]);
    }

    public function testAFakeSenderBootsWithoutKeys(): void
    {
        $this->withoutKeys();

        $app = Kernel::create(EventConfig::load($this->eventsDir(), 'obrok27'), [
            \PDO::class => self::memoryDb(),
            PushSenderInterface::class => new SpyPushSender(),
        ]);

        self::assertSame(200, $this->request($app, 'GET', '/')->getStatusCode());
    }

    public function testAnEventWithoutPushNeedsNoKeys(): void
    {
        $this->withoutKeys();

        // the 'programs' fixture event has features ['programs'] only
        $app = Kernel::create(EventConfig::load(dirname(__DIR__) . '/fixtures/events', 'programs'), [\PDO::class => self::memoryDb()]);

        self::assertInstanceOf(App::class, $app);
    }

    /** The sender is built at boot; the database it sends from is opened on the first send only. */
    public function testBootingTheSenderOpensNoDatabase(): void
    {
        $opened = false;
        $app = Kernel::create(EventConfig::load($this->eventsDir(), 'obrok27'), [
            \PDO::class => function () use (&$opened): \PDO {
                $opened = true;

                return AppTestCase::memoryDb();
            },
        ]);

        $this->request($app, 'GET', '/');

        self::assertFalse($opened, 'the homepage must not open the push database');
        self::assertInstanceOf(\App\Push\WebPushSender::class, $app->getContainer()->get(PushSenderInterface::class));
    }

    /** A booted push event always has a key, so the button no longer asks for one. */
    public function testThePushButtonNoLongerWaitsForTheKeyGlobal(): void
    {
        $this->withoutKeys();

        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('data-push-toggle', $html);
    }
}
