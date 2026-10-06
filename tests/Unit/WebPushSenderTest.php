<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\EndpointPolicy;
use App\Push\IsolatingWebPush;
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
use Psr\Http\Message\RequestInterface;
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

    /**
     * @param list<Response|\Throwable>|\Closure(\Psr\Http\Message\RequestInterface): (Response|\Throwable) $answers
     *        what the push service answers, in order, or per request
     * @param bool $checkKeys false lets an unusable key reach the library's encryption
     */
    private function sender(array|\Closure $answers, bool $checkKeys = true): WebPushSender
    {
        $stack = HandlerStack::create($answers instanceof \Closure
            ? static function (\Psr\Http\Message\RequestInterface $request) use ($answers): \GuzzleHttp\Promise\PromiseInterface {
                $answer = $answers($request);

                return $answer instanceof \Throwable
                    ? \GuzzleHttp\Promise\Create::rejectionFor($answer)
                    : \GuzzleHttp\Promise\Create::promiseFor($answer);
            }
            : new MockHandler($answers));
        $stack->push(Middleware::history($this->history));
        $repository = $this->repository;

        return new WebPushSender(
            repository: static fn (): SubscriptionRepository => $repository,
            endpoints: new EndpointPolicy(['push.example', '*.push.example']),
            vapidPublicKey: self::$vapid['publicKey'],
            vapidPrivateKey: self::$vapid['privateKey'],
            vapidSubject: 'mailto:tests@example.invalid',
            clientOptions: ['handler' => $stack],
            logger: $this->logger,
            report: function (\Throwable $e): void {
                $this->reported[] = $e;
            },
            checkKeys: $checkKeys,
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

        self::assertSame(['recipients' => 2, 'sent' => 1, 'removed' => 1, 'failed' => 0], $result);
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

        self::assertSame(['recipients' => 3, 'sent' => 1, 'removed' => 0, 'failed' => 2], $result);
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

        self::assertSame(['recipients' => 1, 'sent' => 0, 'removed' => 0, 'failed' => 1], $result);
        self::assertCount(1, $this->reported);
        self::assertCount(1, $this->logger->records);
        self::assertSame(
            ['event' => 'korbo26', 'target' => 'programme', 'recipients' => 1, 'sent' => 0, 'removed' => 0, 'failed' => 1],
            $this->logger->records[0]['context'],
            'a programme send: recipients counts the rows the TIE codes matched',
        );
    }

    /** NEW-C1: one subscription whose key is not on the curve used to cost every reader the message. */
    public function testAPoisonedRowAmongValidOnesIsDroppedAndTheRestAreSent(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->subscribe('https://push.example/' . $name);
        }
        $this->repository->save(['endpoint' => 'https://push.example/poison', 'keys' => ['p256dh' => SubscriptionKeysTest::OFF_CURVE_PUBLIC, 'auth' => self::$browser['auth']]], 'korbo26');

        $result = $this->sender(array_fill(0, 5, new Response(201)))->sendToEvent('korbo26', 'Korbo', 'Nástup v 8:00.');

        self::assertSame(['recipients' => 6, 'sent' => 5, 'removed' => 1, 'failed' => 0], $result);
        self::assertNull($this->repository->find('korbo26', 'https://push.example/poison'));
        self::assertCount(5, $this->history, 'the poisoned row was never sent');
    }

    /**
     * The same batch with the key check out of the way: the row reaches the library, its
     * encryption throws inside flush(), and the batch still goes out without it.
     */
    public function testARowWhoseEncryptionThrowsInsideTheBatchIsDroppedAndTheRestAreSent(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->subscribe('https://push.example/' . $name);
        }
        $this->repository->save(['endpoint' => 'https://push.example/poison', 'keys' => ['p256dh' => SubscriptionKeysTest::OFF_CURVE_PUBLIC, 'auth' => self::$browser['auth']]], 'korbo26');

        $result = $this->sender(array_fill(0, 3, new Response(201)), checkKeys: false)->sendToEvent('korbo26', 'Korbo', 'Nástup v 8:00.');

        self::assertSame(['recipients' => 4, 'sent' => 3, 'removed' => 1, 'failed' => 0], $result);
        self::assertNull($this->repository->find('korbo26', 'https://push.example/poison'));
        self::assertCount(3, $this->history);
        self::assertSame([], $this->reported, 'one unusable row is the row, not a fault');
    }

    /** Every row failing to prepare inside the batch: counted as failed, reported once, nothing deleted. */
    public function testWhenEveryRowOfTheBatchThrowsInsideItNothingIsDeleted(): void
    {
        foreach (['x', 'y'] as $name) {
            $this->repository->save(['endpoint' => 'https://push.example/' . $name, 'keys' => ['p256dh' => SubscriptionKeysTest::OFF_CURVE_PUBLIC, 'auth' => self::$browser['auth']]], 'korbo26');
        }

        $result = $this->sender([], checkKeys: false)->sendToEvent('korbo26', 'Korbo', 'Nástup v 8:00.');

        self::assertSame(['recipients' => 2, 'sent' => 0, 'removed' => 0, 'failed' => 2], $result);
        self::assertSame(2, $this->repository->count('korbo26'));
        self::assertCount(1, $this->reported);
        self::assertSame([], $this->history);
    }

    /** A throw while preparing every row is the library or the platform, not the rows: nothing is deleted. */
    public function testWhenEveryQueuedRowFailsToPrepareNothingIsDeleted(): void
    {
        $error = new \ErrorException('boom');
        $all = [['endpoint' => 'https://push.example/a', 'error' => $error], ['endpoint' => 'https://push.example/b', 'error' => $error]];

        self::assertSame(['delete' => [], 'failed' => 2, 'report' => $error], WebPushSender::settleUnprepared(2, $all));
        self::assertSame(['delete' => ['https://push.example/b'], 'failed' => 0, 'report' => null], WebPushSender::settleUnprepared(3, [$all[1]]));
        self::assertSame(['delete' => [], 'failed' => 0, 'report' => null], WebPushSender::settleUnprepared(3, []));
    }

    /**
     * A strike needs a delivery on the same push service in the same batch: `a` always
     * arrives, so `b`'s refusals are about `b`.
     */
    public function testFiveFailedSendsInARowDropASubscriptionAndADeliveryResetsTheCount(): void
    {
        $this->subscribe('https://push.example/a');
        $this->subscribe('https://push.example/b');
        $bFails = static fn (RequestInterface $r): Response => new Response(str_ends_with((string) $r->getUri(), '/b') ? 500 : 201);
        for ($i = 1; $i <= 4; $i++) {
            $this->sender($bFails)->sendToEvent('korbo26', 'Korbo', 'Zpráva ' . $i);
        }
        $this->sender(static fn (): Response => new Response(201))->sendToEvent('korbo26', 'Korbo', 'Doručeno');
        for ($i = 1; $i <= 4; $i++) {
            $this->sender($bFails)->sendToEvent('korbo26', 'Korbo', 'Zpráva ' . $i);
        }
        self::assertSame(2, $this->repository->count('korbo26'), 'the delivery in the middle reset the strikes');

        $this->sender($bFails)->sendToEvent('korbo26', 'Korbo', 'Pátá');
        self::assertNull($this->repository->find('korbo26', 'https://push.example/b'));
        self::assertNotNull($this->repository->find('korbo26', 'https://push.example/a'));
        self::assertSame(5, WebPushSender::MAX_FAILURES);
    }

    /** FF-I1: the server's own outage (no route out, DNS, a throttled sender) is not the readers' fault. */
    public function testSendsWhereNothingWasDeliveredStrikeNobody(): void
    {
        $this->subscribe('https://push.example/a');
        $this->subscribe('https://apple.push.example/b');
        $down = static fn (RequestInterface $r): \Throwable => new ConnectException('no route to host', $r);
        for ($i = 1; $i <= WebPushSender::MAX_FAILURES + 1; $i++) {
            $result = $this->sender($down)->sendToEvent('korbo26', 'Korbo', 'Zpráva ' . $i);
            self::assertSame(['recipients' => 2, 'sent' => 0, 'removed' => 0, 'failed' => 2], $result);
        }

        self::assertSame(2, $this->repository->count('korbo26'));
    }

    /** FF-I1: one push service down while the others deliver strikes none of its rows. */
    public function testAnOutageOfOnePushServiceStrikesNoneOfItsRows(): void
    {
        $this->subscribe('https://push.example/a');
        $this->subscribe('https://apple.push.example/x');
        $this->subscribe('https://apple.push.example/y');
        $appleDown = static fn (RequestInterface $r): Response => new Response($r->getUri()->getHost() === 'apple.push.example' ? 503 : 201);
        for ($i = 1; $i <= WebPushSender::MAX_FAILURES + 1; $i++) {
            $result = $this->sender($appleDown)->sendToEvent('korbo26', 'Korbo', 'Zpráva ' . $i);
            self::assertSame(['recipients' => 3, 'sent' => 1, 'removed' => 0, 'failed' => 2], $result);
        }

        self::assertSame(3, $this->repository->count('korbo26'));
    }

    public function testOnlyRowsOnAServiceThatDeliveredInTheBatchAreStruck(): void
    {
        self::assertSame(
            ['https://push.example/b'],
            WebPushSender::strikable(
                ['https://PUSH.example/a'],
                ['https://push.example/b', 'https://apple.push.example/x', 'not a url'],
            ),
        );
        self::assertSame([], WebPushSender::strikable([], ['https://push.example/b']));
    }

    /** The library's "GMP or BCMath" advice reaches neither PHP's error handler nor the log. */
    public function testTheLibrarysPlatformAdviceGoesToTheLogger(): void
    {
        $logger = new RecordingLogger();
        $raised = [];
        set_error_handler(static function (int $errno, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });
        try {
            $webPush = WebPushSender::webPush(['subject' => 'mailto:t@example.invalid'] + self::$vapid, [], $logger);
        } finally {
            restore_error_handler();
        }

        self::assertInstanceOf(IsolatingWebPush::class, $webPush);
        self::assertSame([], $raised);
        self::assertSame([], $logger->records);
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

    /** The worker forwards `programme` to the open app, which then refreshes the Program screen too. */
    public function testThePayloadCarriesTheProgrammeOrNull(): void
    {
        self::assertSame(
            ['title' => 'Změna', 'body' => 'Nástup v 18:00', 'icon' => 'events/korbo26/logo.png', 'url' => '/korbo26/novinky', 'programme' => null],
            json_decode(WebPushSender::payload('Změna', 'Nástup v 18:00', 'events/korbo26/logo.png', '/korbo26/novinky', null), true),
        );
        self::assertSame(7, json_decode(WebPushSender::payload('T', 'B', null, null, 7), true)['programme']);
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
