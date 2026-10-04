<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\EndpointPolicy;
use PHPUnit\Framework\TestCase;

final class EndpointPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['PUSH_ENDPOINT_HOSTS']);
    }

    /** @return iterable<string, array{string}> */
    public static function realEndpoints(): iterable
    {
        yield 'chrome' => ['https://fcm.googleapis.com/fcm/send/abc:def'];
        yield 'old chrome' => ['https://android.googleapis.com/gcm/send/abc'];
        yield 'safari' => ['https://web.push.apple.com/QGx1'];
        yield 'firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAA'];
        yield 'firefox regional' => ['https://eu.push.services.mozilla.com/wpush/v2/gAAAA'];
        yield 'edge' => ['https://wns2-par02p.notify.windows.com/w/?token=BQYAAA'];
        yield 'samsung' => ['https://eu.push.samsungosp.com/abc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('realEndpoints')]
    public function testEachDefaultServiceIsAllowed(string $endpoint): void
    {
        self::assertTrue((new EndpointPolicy(EndpointPolicy::DEFAULT_HOSTS))->allows($endpoint));
    }

    public function testAWildcardNeedsAtLeastOneLabelInFrontAndNothingBehind(): void
    {
        $policy = new EndpointPolicy(EndpointPolicy::DEFAULT_HOSTS);

        self::assertTrue($policy->allows('https://web.push.apple.com/x'));
        self::assertFalse($policy->allows('https://push.apple.com/x'));
        self::assertFalse($policy->allows('https://evil-push.apple.com.example/x'));
        self::assertFalse($policy->allows('https://evilpush.apple.com/x'));
    }

    public function testTheEnvironmentAddsHostsToTheDefaults(): void
    {
        self::assertFalse(EndpointPolicy::fromEnvironment()->allows('https://push.example/x'));

        $_ENV['PUSH_ENDPOINT_HOSTS'] = 'push.example, *.dev.example';

        $policy = EndpointPolicy::fromEnvironment();
        self::assertTrue($policy->allows('https://push.example/x'));
        self::assertTrue($policy->allows('https://a.dev.example/x'));
        self::assertTrue($policy->allows('https://fcm.googleapis.com/x'), 'the defaults stay');
    }

    public function testPlainHttpAndIpLiteralsAreRefused(): void
    {
        $policy = new EndpointPolicy([...EndpointPolicy::DEFAULT_HOSTS, '127.0.0.1', '*.0.0.1']);

        self::assertFalse($policy->allows('http://fcm.googleapis.com/x'));
        self::assertFalse($policy->allows('https://127.0.0.1/x'));
        self::assertFalse($policy->allows('https://[::1]/x'));
        self::assertFalse($policy->allows('not a url'));
        self::assertFalse($policy->allows(''));
    }

    /** Review Focus 3 */
    public function testHostCaseAndPortAndUserinfo(): void
    {
        $policy = new EndpointPolicy(EndpointPolicy::DEFAULT_HOSTS);

        self::assertTrue($policy->allows('https://FCM.GoogleAPIs.com/fcm/send/x'));
        self::assertTrue($policy->allows('https://fcm.googleapis.com:443/fcm/send/x'));
        self::assertFalse($policy->allows('https://fcm.googleapis.com:8443/fcm/send/x'));
        self::assertFalse($policy->allows('https://fcm.googleapis.com@evil.example/x'));
        self::assertFalse($policy->allows('https://user:pass@fcm.googleapis.com/x'));
        self::assertFalse($policy->allows('https://fcm.googleapis.com./x'), 'a trailing dot is another name');
    }
}
