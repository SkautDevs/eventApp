<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Telemetry\Scrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

final class ScrubberTest extends TestCase
{
    public function testTheAppsSecretsAreReplacedWhereverTheyAppear(): void
    {
        $event = Event::createEvent();
        $event->setTransaction('GET /korbo26/admin/notify?token=abc');
        $event->setRequest([
            // not an admin route: those have their whole body replaced (see the admin tests below)
            'url' => 'https://app.example/korbo26/profil/tie?token=abc&page=2',
            'method' => 'POST',
            'query_string' => 'token=abc&page=2',
            'data' => ['tieCode' => 'KORBO1', 'token' => 'abc', 'return' => 'programy'],
            'headers' => ['Authorization' => ['Bearer key'], 'cookie' => 'eventapp=session', 'Accept' => ['text/html']],
        ]);
        $event->setContext('http', ['url' => 'https://app.example/korbo26/admin/notify?token=abc']);
        $event->setMessage('failed for %s', ['https://app.example/?token=abc'], 'failed for https://app.example/?token=abc');

        $scrubbed = Scrubber::scrub($event);

        $request = $scrubbed->getRequest();
        self::assertSame('GET /korbo26/admin/notify?token=<redacted>', $scrubbed->getTransaction());
        self::assertSame('https://app.example/korbo26/profil/tie?token=<redacted>&page=2', $request['url']);
        self::assertSame('token=<redacted>&page=2', $request['query_string']);
        self::assertSame(['tieCode' => '<redacted>', 'token' => '<redacted>', 'return' => 'programy'], $request['data']);
        self::assertSame(['Authorization' => '<redacted>', 'cookie' => '<redacted>', 'Accept' => ['text/html']], $request['headers']);
        self::assertSame('https://app.example/korbo26/admin/notify?token=<redacted>', $scrubbed->getContexts()['http']['url']);
        self::assertSame(['https://app.example/?token=<redacted>'], $scrubbed->getMessageParams());
        self::assertSame('failed for https://app.example/?token=<redacted>', $scrubbed->getMessageFormatted());
    }

    public function testASubscribeBodyIsReplacedWhole(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://app.example/korbo26/push/subscribe',
            'method' => 'POST',
            'data' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'keys' => ['p256dh' => 'PK', 'auth' => 'AT']],
        ]);

        self::assertSame('<subscription redacted>', Scrubber::scrub($event)->getRequest()['data']);
    }

    public function testAnUnsubscribeBodyIsReplacedWhole(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://app.example/korbo26/push/unsubscribe',
            'method' => 'POST',
            'data' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'],
        ]);

        self::assertSame('<subscription redacted>', Scrubber::scrub($event)->getRequest()['data']);
    }

    public function testAnAdminSendBodyIsReplacedWhole(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://app.example/obrok27/admin/notify',
            'method' => 'POST',
            'data' => ['csrf' => str_repeat('a', 32), 'title' => 'Bourka', 'body' => 'Schovejte se', 'signature' => 'Jana Novakova', 'target' => ''],
        ]);

        self::assertSame('<admin form redacted>', Scrubber::scrub($event)->getRequest()['data']);
    }

    public function testAnAdminHideBodyIsReplacedWhole(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://app.example/obrok27/admin/notify/12/hidden',
            'method' => 'POST',
            'data' => ['csrf' => str_repeat('a', 32), 'hidden' => '1', 'signature' => 'Jana Novakova', 'page' => '1'],
        ]);

        self::assertSame('<admin form redacted>', Scrubber::scrub($event)->getRequest()['data']);
    }

    public function testAnAdminFormSentAsAStringIsReplacedWhole(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://app.example/obrok27/admin/notify?page=2',
            'method' => 'POST',
            'data' => 'csrf=abc&title=Bourka&signature=Jana',
        ]);

        self::assertSame('<admin form redacted>', Scrubber::scrub($event)->getRequest()['data']);
    }

    public function testAnUnrelatedRouteKeepsItsNonSensitiveFields(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://app.example/obrok27/profil/tie',
            'method' => 'POST',
            'data' => ['tieCode' => 'OBROK1', 'return' => 'programy'],
        ]);

        self::assertSame(['tieCode' => '<redacted>', 'return' => 'programy'], Scrubber::scrub($event)->getRequest()['data']);
    }

    public function testAnEventWithNoSecretIsReturnedUnchanged(): void
    {
        $request = [
            'url' => 'https://app.example/korbo26/programy',
            'method' => 'GET',
            'query_string' => 'page=2',
            'data' => ['return' => 'programy'],
            'headers' => ['Accept' => ['text/html']],
        ];
        $event = Event::createEvent();
        $event->setTransaction('GET /programy');
        $event->setRequest($request);

        $scrubbed = Scrubber::scrub($event);

        self::assertSame($request, $scrubbed->getRequest());
        self::assertSame('GET /programy', $scrubbed->getTransaction());
        self::assertNull($scrubbed->getMessage());
    }

    public function testATokenThatIsOnlyASuffixOfAnotherParameterIsKept(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://app.example/x?csrftoken=1&token=2', 'query_string' => 'csrftoken=1&token=2']);

        $request = Scrubber::scrub($event)->getRequest();

        self::assertSame('https://app.example/x?csrftoken=1&token=<redacted>', $request['url']);
        self::assertSame('csrftoken=1&token=<redacted>', $request['query_string']);
    }
}
