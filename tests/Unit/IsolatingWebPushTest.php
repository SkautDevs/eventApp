<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\IsolatingWebPush;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Functional\AppTestCase;

/** The library's batch, with one notification whose encryption throws. */
final class IsolatingWebPushTest extends TestCase
{
    public function testAnUnpreparableNotificationIsSetAsideAndTheRestAreSent(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201), new Response(201)]));
        $stack->push(Middleware::history($history));
        $webPush = new IsolatingWebPush(['VAPID' => ['subject' => 'mailto:t@example.invalid'] + VAPID::createVapidKeys()], [], 5, ['handler' => $stack], new NullLogger());
        $good = ['publicKey' => AppTestCase::BROWSER_KEYS['p256dh'], 'authToken' => AppTestCase::BROWSER_KEYS['auth']];
        $webPush->queueNotification(Subscription::create(['endpoint' => 'https://push.example/a'] + $good), 'x');
        $webPush->queueNotification(Subscription::create(['endpoint' => 'https://push.example/bad', 'publicKey' => SubscriptionKeysTest::OFF_CURVE_PUBLIC, 'authToken' => $good['authToken']]), 'x');
        $webPush->queueNotification(Subscription::create(['endpoint' => 'https://push.example/c'] + $good), 'x');

        $reports = iterator_to_array($webPush->flush(), false);

        self::assertCount(2, $reports);
        self::assertSame(['https://push.example/a', 'https://push.example/c'], array_map(static fn ($r): string => $r->getEndpoint(), $reports));
        self::assertCount(2, $history);
        self::assertSame(['https://push.example/bad'], array_column($webPush->takeUnprepared(), 'endpoint'));
        self::assertSame([], $webPush->takeUnprepared(), 'handed over once');
    }
}
