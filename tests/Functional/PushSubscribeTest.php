<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\SendOutcome;
use App\Push\SubscriptionRepository;

final class PushSubscribeTest extends AppTestCase
{
    private \PDO $pdo;

    private SpyPushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = self::memoryDb();
        // the tests' push service; production allows only the known ones
        $_ENV['PUSH_ENDPOINT_HOSTS'] = 'push.example';
    }

    protected function tearDown(): void
    {
        unset($_ENV['PUSH_ENDPOINT_HOSTS']);
        parent::tearDown();
    }

    private function repo(): SubscriptionRepository
    {
        return new SubscriptionRepository($this->pdo);
    }

    /** a spy sender: the welcome push must never reach a real push service from a test */
    private function overrides(): array
    {
        $this->sender ??= new SpyPushSender();

        return [\PDO::class => $this->pdo, \App\Push\PushSenderInterface::class => $this->sender];
    }

    public function testSubscribeStoresSubscription(): void
    {
        $app = $this->createApp(overrides: $this->overrides());

        $response = $this->request($app, 'POST', '/push/subscribe', [
            'endpoint' => 'https://push.example/xyz',
            'keys' => ['p256dh' => 'PK', 'auth' => 'AT'],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->repo()->count('obrok19'));
        self::assertSame(0, $this->repo()->count('obrok27'));
    }

    public function testInvalidBodyIs400(): void
    {
        $app = $this->createApp(overrides: $this->overrides());

        self::assertSame(400, $this->request($app, 'POST', '/push/subscribe', ['endpoint' => 'x'])->getStatusCode());
        self::assertSame(0, $this->repo()->count());
    }

    public function testABodyCannotChooseItsEvent(): void
    {
        $app = $this->createApp(overrides: $this->overrides());

        $response = $this->request($app, 'POST', '/push/subscribe', [
            'endpoint' => 'https://push.example/xyz',
            'keys' => ['p256dh' => 'PK', 'auth' => 'AT'],
            'event' => 'obrok27',
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->repo()->count('obrok19'));
        self::assertSame(0, $this->repo()->count('obrok27'));
    }

    private const SUB = ['endpoint' => 'https://push.example/xyz', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']];

    public function testASubscriptionCarriesTheLoggedInTieCode(): void
    {
        $app = $this->createApp(overrides: $this->overrides());
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'abc123']);

        $this->request($app, 'POST', '/push/subscribe', self::SUB);

        self::assertSame('ABC123', $this->repo()->forEvent('obrok19')[0]['tieCode']);
    }

    public function testLoggingOutAndResubscribingClearsTheTieCode(): void
    {
        $app = $this->createApp(overrides: $this->overrides());
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        $this->request($app, 'POST', '/push/subscribe', self::SUB);
        $this->request($app, 'POST', '/profil/tie-logout');

        $this->request($app, 'POST', '/push/subscribe', self::SUB);

        self::assertNull($this->repo()->forEvent('obrok19')[0]['tieCode']);
        self::assertSame(1, $this->repo()->count('obrok19'));
    }

    public function testABodyCannotChooseItsTieCode(): void
    {
        $app = $this->createApp(overrides: $this->overrides());

        $this->request($app, 'POST', '/push/subscribe', self::SUB + ['tieCode' => 'ABC123', 'tie_code' => 'ABC123']);

        self::assertNull($this->repo()->forEvent('obrok19')[0]['tieCode']);
    }

    public function testThePageTellsPushJsWhoIsLoggedIn(): void
    {
        $app = $this->createApp();
        self::assertStringContainsString('<meta name="push-identity" content="">', (string) $this->request($app, 'GET', '/')->getBody());

        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);
        self::assertStringContainsString('<meta name="push-identity" content="ABC123">', (string) $this->request($app, 'GET', '/')->getBody());
    }

    public function testANewSubscriptionGetsAWelcomeAtOnce(): void
    {
        $app = $this->createApp(overrides: $this->overrides());

        $response = $this->request($app, 'POST', '/push/subscribe', self::SUB);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['saved' => true, 'welcome' => true], json_decode((string) $response->getBody(), true));
        self::assertSame(1, $this->repo()->count('obrok19'));
        self::assertCount(1, $this->sender->welcomes);
        $welcome = $this->sender->welcomes[0];
        self::assertSame('obrok19', $welcome['event']);
        self::assertSame(self::SUB['endpoint'], $welcome['endpoint']);
        self::assertStringEndsWith('/novinky', (string) $welcome['url']);
        self::assertNotSame('', $welcome['title']);
        self::assertSame([], $this->sender->calls, 'a welcome is not a message to the whole event');
    }

    public function testResendingAKnownSubscriptionStaysSilent(): void
    {
        $app = $this->createApp(overrides: $this->overrides());
        $this->request($app, 'POST', '/push/subscribe', self::SUB);
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'ABC123']);

        $response = $this->request($app, 'POST', '/push/subscribe', self::SUB);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['saved' => true, 'welcome' => null], json_decode((string) $response->getBody(), true));
        self::assertCount(1, $this->sender->welcomes);
    }

    public function testARejectedWelcomeDropsTheSubscriptionSoTheNextTapStartsOver(): void
    {
        $overrides = $this->overrides();
        $this->sender->welcomeOutcome = SendOutcome::Rejected;
        $app = $this->createApp(overrides: $overrides);

        $response = $this->request($app, 'POST', '/push/subscribe', self::SUB);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(
            ['saved' => false, 'welcome' => false, 'error' => 'subscription-rejected'],
            json_decode((string) $response->getBody(), true),
        );
        self::assertSame(0, $this->repo()->count('obrok19'));

        $this->sender->welcomeOutcome = SendOutcome::Delivered;
        $retry = $this->request($app, 'POST', '/push/subscribe', self::SUB);

        self::assertSame(201, $retry->getStatusCode());
        self::assertSame(['saved' => true, 'welcome' => true], json_decode((string) $retry->getBody(), true));
        self::assertCount(2, $this->sender->welcomes, 'the retry is new again and gets its welcome');
        self::assertSame(1, $this->repo()->count('obrok19'));
    }

    /** A push-service hiccup must not throw away a perfectly good subscription. */
    public function testAFailedWelcomeKeepsTheSubscriptionAndSaysSo(): void
    {
        $overrides = $this->overrides();
        $this->sender->welcomeOutcome = SendOutcome::Failed;
        $app = $this->createApp(overrides: $overrides);

        $response = $this->request($app, 'POST', '/push/subscribe', self::SUB);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['saved' => true, 'welcome' => false], json_decode((string) $response->getBody(), true));
        self::assertSame(1, $this->repo()->count('obrok19'));
    }

    public function testASenderThatThrowsIsAFailedWelcome(): void
    {
        $sender = new class implements \App\Push\PushSenderInterface {
            public function sendToEvent(string $event, string $title, string $body, ?string $icon = null, ?string $url = null, ?array $tieCodes = null): array
            {
                return ['sent' => 0, 'removed' => 0];
            }

            public function sendToSubscription(string $event, string $endpoint, string $title, string $body, ?string $icon = null, ?string $url = null): \App\Push\SendOutcome
            {
                throw new \ErrorException('[VAPID] Public key should be 65 bytes long when decoded.');
            }
        };
        $app = $this->createApp(overrides: [\PDO::class => $this->pdo, \App\Push\PushSenderInterface::class => $sender]);

        $response = $this->request($app, 'POST', '/push/subscribe', self::SUB);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['saved' => true, 'welcome' => false], json_decode((string) $response->getBody(), true));
        self::assertSame(1, $this->repo()->count('obrok19'), 'an exception proves nothing about the subscription');
    }

    public function testARejectedBodyGetsNoWelcome(): void
    {
        $app = $this->createApp(overrides: $this->overrides());

        $this->request($app, 'POST', '/push/subscribe', ['endpoint' => 'https://push.example/xyz']);

        self::assertSame([], $this->sender->welcomes);
    }

    public function testAnEndpointOutsideTheKnownPushServicesIs400AndNothingIsSent(): void
    {
        $app = $this->createApp(overrides: $this->overrides());

        $response = $this->request($app, 'POST', '/push/subscribe', [
            'endpoint' => 'https://intranet.example/hook',
            'keys' => ['p256dh' => 'PK', 'auth' => 'AT'],
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->repo()->count());
        self::assertSame([], $this->sender->welcomes, 'the server must not POST to a host the body chose');
    }

    public function testTheSameBrowserSubscribedToTwoEventsKeepsBothRows(): void
    {
        $overrides = $this->overrides();
        $this->request($this->createApp('obrok19', overrides: $overrides), 'POST', '/push/subscribe', self::SUB);
        $this->request($this->createApp('korbo26', overrides: $overrides), 'POST', '/push/subscribe', self::SUB);

        self::assertSame(1, $this->repo()->count('obrok19'));
        self::assertSame(1, $this->repo()->count('korbo26'));
        self::assertCount(2, $this->sender->welcomes, 'each event welcomes the browser once');
    }
}
