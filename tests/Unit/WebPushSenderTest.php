<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\EndpointPolicy;
use App\Push\SendOutcome;
use App\Push\SubscriptionRepository;
use App\Push\WebPushSender;
use App\Storage\Database;
use App\Storage\Migrator;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;

/**
 * The real library against a scripted push service: the payload is really encrypted and
 * signed, only the HTTP answer is mocked. Every key is generated per run, so no key
 * material lives in the repository.
 */
final class WebPushSenderTest extends TestCase
{
    /** @var array{publicKey: string, privateKey: string} */
    private static array $vapid;

    /** @var array{p256dh: string, auth: string} a browser's subscription keys */
    private static array $browser;

    private SubscriptionRepository $repository;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array}> */
    private array $history = [];

    /** @var list<\Throwable> what reached the collector */
    private array $reported = [];

    private RecordingLogger $logger;

    public static function setUpBeforeClass(): void
    {
        self::$vapid = VAPID::createVapidKeys();
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        self::$browser = [
            'p256dh' => self::base64Url("\x04" . str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT)),
            'auth' => self::base64Url(random_bytes(16)),
        ];
    }

    protected function setUp(): void
    {
        $pdo = Database::open(':memory:');
        (new Migrator())->migrate($pdo);
        $this->repository = new SubscriptionRepository($pdo);
        $this->history = [];
        $this->reported = [];
        $this->logger = new RecordingLogger();
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function subscribe(string $endpoint, ?string $tieCode = null): void
    {
        $this->repository->save(['endpoint' => $endpoint, 'keys' => self::$browser], 'korbo26', $tieCode);
    }

    /** @param list<Response|\Throwable> $answers what the push service answers, in order */
    private function sender(array $answers): WebPushSender
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->history));
        $repository = $this->repository;

        return new WebPushSender(
            repository: static fn (): SubscriptionRepository => $repository,
            endpoints: new EndpointPolicy(['push.example']),
            vapidPublicKey: self::$vapid['publicKey'],
            vapidPrivateKey: self::$vapid['privateKey'],
            vapidSubject: 'mailto:tests@example.invalid',
            clientOptions: ['handler' => $stack],
            logger: $this->logger,
            report: function (\Throwable $e): void {
                $this->reported[] = $e;
            },
        );
    }

    private function welcome(WebPushSender $sender, string $endpoint = 'https://push.example/a'): SendOutcome
    {
        return $sender->sendToSubscription('korbo26', $endpoint, 'Korbo', 'Notifikace jsou zapnuté. Novinky z akce ti budou chodit sem.');
    }

    public function testAnAcceptedWelcomeIsDelivered(): void
    {
        $this->subscribe('https://push.example/a');

        self::assertSame(SendOutcome::Delivered, $this->welcome($this->sender([new Response(201)])));
        self::assertSame(1, $this->repository->count('korbo26'));
    }

    public function testAGoneSubscriptionIsRejectedAndItsRowDeleted(): void
    {
        foreach ([404, 410] as $status) {
            $this->subscribe('https://push.example/a');

            self::assertSame(SendOutcome::Rejected, $this->welcome($this->sender([new Response($status)])), (string) $status);
            self::assertSame(0, $this->repository->count('korbo26'), (string) $status);
        }
    }

    /** Nothing here proves the subscription dead, so nothing is deleted. */
    public function testAnythingElseIsAFailureAndTheRowIsKept(): void
    {
        $this->subscribe('https://push.example/a');

        foreach ([
            'HTTP 500' => new Response(500),
            'HTTP 429' => new Response(429),
            'timeout' => new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://push.example/a')),
        ] as $case => $answer) {
            self::assertSame(SendOutcome::Failed, $this->welcome($this->sender([$answer])), $case);
            self::assertSame(1, $this->repository->count('korbo26'), $case);
        }
    }

    public function testAWelcomeToARowThatIsGoneIsRejectedWithoutARequest(): void
    {
        self::assertSame(SendOutcome::Rejected, $this->welcome($this->sender([])));
        self::assertSame([], $this->history);
    }

    /** The library's default is 30 s; an admin batch has to finish inside nginx's 45 s. */
    public function testEveryPushRequestCarriesTheFiveSecondTimeout(): void
    {
        $this->subscribe('https://push.example/a');
        $this->subscribe('https://push.example/b');

        $this->sender([new Response(201), new Response(201)])->sendToEvent('korbo26', 'Korbo', 'Nástup v 8:00.');

        self::assertSame(5, WebPushSender::TIMEOUT_SECONDS);
        self::assertCount(2, $this->history);
        foreach ($this->history as $entry) {
            self::assertSame(5, $entry['options']['timeout']);
        }
    }

    public function testARowSavedBeforeTheAllowListIsDeletedAtSendTimeAndNeverCalled(): void
    {
        $this->subscribe('https://push.example/a');
        $this->subscribe('https://intranet.example/hook');

        $result = $this->sender([new Response(201)])->sendToEvent('korbo26', 'Korbo', 'Nástup v 8:00.');

        self::assertSame(['sent' => 1, 'removed' => 1], $result);
        self::assertCount(1, $this->history);
        self::assertSame('https://push.example/a', (string) $this->history[0]['request']->getUri());
        self::assertNull($this->repository->find('korbo26', 'https://intranet.example/hook'));
    }

    /** Final review M2: a push service saying no is counted and logged, not filed as an exception. */
    public function testAFailedReportIsCountedAndLoggedOnce(): void
    {
        $this->subscribe('https://push.example/a');
        $this->subscribe('https://push.example/b');
        $this->subscribe('https://push.example/c');

        $result = $this->sender([new Response(201), new Response(500), new Response(403)])
            ->sendToEvent('korbo26', 'Korbo', 'Nástup v 8:00.', tieCodes: null);

        self::assertSame(['sent' => 1, 'removed' => 0], $result, 'the interface still answers sent and removed');
        self::assertSame([], $this->reported, 'an HTTP answer is not an exception');
        self::assertSame([[
            'level' => 'warning',
            'message' => 'push.failed',
            'context' => ['event' => 'korbo26', 'target' => 'event', 'recipients' => 3, 'sent' => 1, 'removed' => 0, 'failed' => 2],
        ]], $this->logger->records);
    }

    public function testABatchWithoutFailuresLogsNothing(): void
    {
        $this->subscribe('https://push.example/a');

        $this->sender([new Response(201)])->sendToEvent('korbo26', 'Korbo', 'Nástup v 8:00.');

        self::assertSame([], $this->logger->records);
    }

    /** Final review M2: a throwable out of flush() reaches the collector and counts as failed. */
    public function testAThrowableInTheBatchIsCollectedAndCountedAsFailed(): void
    {
        $this->subscribe('https://push.example/a', 'KORBO1');
        $this->subscribe('https://push.example/b');

        // not a Guzzle exception: the library's rejection handler cannot map it and flush() throws
        $result = $this->sender([new \RuntimeException('handler broke')])->sendToEvent('korbo26', 'Korbo', 'Nástup v 8:00.', tieCodes: ['KORBO1']);

        self::assertSame(['sent' => 0, 'removed' => 0], $result);
        self::assertCount(1, $this->reported);
        self::assertCount(1, $this->logger->records);
        self::assertSame(
            ['event' => 'korbo26', 'target' => 'programme', 'recipients' => 1, 'sent' => 0, 'removed' => 0, 'failed' => 1],
            $this->logger->records[0]['context'],
            'a programme send: recipients counts the rows the TIE codes matched',
        );
    }

    public function testAThrowableInTheWelcomeIsCollected(): void
    {
        $this->subscribe('https://push.example/a');

        self::assertSame(SendOutcome::Failed, $this->welcome($this->sender([new \RuntimeException('handler broke')])));
        self::assertCount(1, $this->reported);
        self::assertSame([], $this->logger->records, 'the welcome is no batch');
    }

    public function testEachInvalidVapidValueNamesItsVariable(): void
    {
        $valid = ['public' => self::$vapid['publicKey'], 'private' => self::$vapid['privateKey'], 'subject' => 'mailto:tests@example.invalid'];
        foreach ([
            'VAPID_PUBLIC_KEY' => [
                ['public' => ''],
                ['public' => 'test-key'],
                ['public' => self::base64Url("\x05" . random_bytes(64))],
                ['public' => self::base64Url("\x04" . random_bytes(32))],
            ],
            'VAPID_PRIVATE_KEY' => [
                ['private' => ''],
                ['private' => 'not base64!'],
                ['private' => self::base64Url(random_bytes(31))],
            ],
            'VAPID_SUBJECT' => [
                ['subject' => ''],
                ['subject' => 'info@example.org'],
                ['subject' => 'http://example.org/kontakt'],
            ],
        ] as $variable => $cases) {
            foreach ($cases as $case) {
                $values = $case + $valid;
                // the failure is raised outside the try: PHPUnit's own AssertionFailedError
                // is a \RuntimeException too, and the catch would swallow it
                $thrown = null;
                try {
                    new WebPushSender(
                        repository: static fn (): SubscriptionRepository => throw new \LogicException('never resolved'),
                        endpoints: new EndpointPolicy([]),
                        vapidPublicKey: $values['public'],
                        vapidPrivateKey: $values['private'],
                        vapidSubject: $values['subject'],
                    );
                } catch (\RuntimeException $e) {
                    $thrown = $e;
                }
                self::assertNotNull($thrown, sprintf('%s accepted %s', $variable, var_export($case, true)));
                self::assertStringStartsWith($variable, $thrown->getMessage());
                self::assertStringNotContainsString(self::$vapid['privateKey'], $thrown->getMessage(), 'no message carries a key');
                self::assertStringNotContainsString(self::$vapid['publicKey'], $thrown->getMessage(), 'no message carries a key');
            }
        }
    }

    /**
     * A pair straight from the library passes the check, and the sender built from it signs
     * with exactly that pair: the push service sees the public key and the subject.
     */
    public function testAValidThrowawayPairBoots(): void
    {
        $this->subscribe('https://push.example/a');
        $stack = HandlerStack::create(new MockHandler([new Response(201)]));
        $stack->push(Middleware::history($this->history));
        $repository = $this->repository;

        $sender = new WebPushSender(
            repository: static fn (): SubscriptionRepository => $repository,
            endpoints: new EndpointPolicy(['push.example']),
            vapidPublicKey: self::$vapid['publicKey'],
            vapidPrivateKey: self::$vapid['privateKey'],
            vapidSubject: 'https://example.org/kontakt',
            clientOptions: ['handler' => $stack],
        );

        self::assertSame(SendOutcome::Delivered, $this->welcome($sender));
        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        self::assertStringContainsString('p256ecdsa=' . self::$vapid['publicKey'], $request->getHeaderLine('Crypto-Key'));
        // the signed claims name the subject it was given
        $claims = json_decode((string) base64_decode(strtr(explode('.', $request->getHeaderLine('Authorization'))[1], '-_', '+/')), true);
        self::assertSame('https://example.org/kontakt', $claims['sub']);
    }
}

/** The log lines a test needs to see, nothing more. */
final class RecordingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
