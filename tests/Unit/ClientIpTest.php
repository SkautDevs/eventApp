<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ClientIp;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientIpTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['TRUSTED_PROXY_COUNT']);
    }

    private static function request(?string $forwardedFor = null, string $remote = '172.18.0.3'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/korbo26/profil/tie', ['REMOTE_ADDR' => $remote]);

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }

    public function testTheRemoteAddressByDefault(): void
    {
        self::assertSame('172.18.0.3', ClientIp::of(self::request('203.0.113.7')));
    }

    public function testOneTrustedProxyTakesTheLastForwardedAddress(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';

        self::assertSame('203.0.113.7', ClientIp::of(self::request('198.51.100.1, 203.0.113.7')));
    }

    public function testTwoTrustedProxiesTakeTheOneBeforeIt(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '2';

        self::assertSame('198.51.100.1', ClientIp::of(self::request('198.51.100.1, 203.0.113.7')));
    }

    public function testAnAbsentHeaderFallsBack(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';

        self::assertSame('172.18.0.3', ClientIp::of(self::request()));
    }

    public function testAnIpv6AddressIsKept(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';

        self::assertSame('2001:db8::1', ClientIp::of(self::request('2001:db8::1')));
    }

    /** Review Focus 2: an attacker-written chain must never become the throttle's key. */
    public function testGarbageInTheForwardedChainFallsBackToTheRemoteAddress(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        self::assertSame('172.18.0.3', ClientIp::of(self::request('203.0.113.7, <script>')));
        self::assertSame('172.18.0.3', ClientIp::of(self::request(' , ')));

        $_ENV['TRUSTED_PROXY_COUNT'] = '3';
        self::assertSame('172.18.0.3', ClientIp::of(self::request('203.0.113.7')), 'fewer hops than proxies');
    }

    public function testANonNumericProxyCountIsZero(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = 'yes';

        self::assertSame('172.18.0.3', ClientIp::of(self::request('203.0.113.7')));
    }

    public function testNoRemoteAddressIsUnknown(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/korbo26/profil/tie');

        self::assertSame('unknown', ClientIp::of($request));
    }
}
