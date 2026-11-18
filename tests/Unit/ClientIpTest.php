<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Environment;
use App\Http\ClientIp;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientIpTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['TRUSTED_PROXY_COUNT'], $_ENV['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');
    }

    /** Built from the values the test put into the environment, as the container builds it. */
    private static function clientIp(): ClientIp
    {
        $environment = Environment::fromGlobals();

        return new ClientIp($environment->trustedProxyCount(), $environment->trustedProxies());
    }

    private static function request(?string $forwardedFor = null, string $remote = '172.18.0.3'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/korbo26/profil/propojit', ['REMOTE_ADDR' => $remote]);

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }

    public function testTheRemoteAddressByDefault(): void
    {
        self::assertSame('172.18.0.3', self::clientIp()->of(self::request('203.0.113.7')));
    }

    public function testOneTrustedProxyTakesTheLastForwardedAddress(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';

        self::assertSame('203.0.113.7', self::clientIp()->of(self::request('198.51.100.1, 203.0.113.7')));
    }

    public function testTwoTrustedProxiesTakeTheOneBeforeIt(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '2';

        self::assertSame('198.51.100.1', self::clientIp()->of(self::request('198.51.100.1, 203.0.113.7')));
    }

    public function testAnAbsentHeaderFallsBack(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';

        self::assertSame('172.18.0.3', self::clientIp()->of(self::request()));
    }

    public function testAnIpv6AddressIsKept(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';

        self::assertSame('2001:db8::1', self::clientIp()->of(self::request('2001:db8::1')));
    }

    /** Review Focus 2: an attacker-written chain must never become the throttle's key. */
    public function testGarbageInTheForwardedChainFallsBackToTheRemoteAddress(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        self::assertSame('172.18.0.3', self::clientIp()->of(self::request('203.0.113.7, <script>')));
        self::assertSame('172.18.0.3', self::clientIp()->of(self::request(' , ')));

        $_ENV['TRUSTED_PROXY_COUNT'] = '3';
        self::assertSame('172.18.0.3', self::clientIp()->of(self::request('203.0.113.7')), 'fewer hops than proxies');
    }

    public function testANonNumericProxyCountIsZero(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = 'yes';

        self::assertSame('172.18.0.3', self::clientIp()->of(self::request('203.0.113.7')));
    }

    public function testNoRemoteAddressIsUnknown(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/korbo26/profil/propojit');

        self::assertSame('unknown', self::clientIp()->of($request));
    }

    public function testAPublicConnectionIsNeverAProxy(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        self::assertSame('198.51.100.9', self::clientIp()->of(self::request('203.0.113.7', '198.51.100.9')), 'a client connecting directly wrote that header itself');
    }

    public function testLoopbackAndPrivateConnectionsAreProxies(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.254', '192.168.1.1', '::1', 'fd12:3456::1'] as $proxy) {
            self::assertSame('203.0.113.7', self::clientIp()->of(self::request('203.0.113.7', $proxy)), $proxy);
        }
        foreach (['172.32.0.1', '11.0.0.1', '2001:db8::1'] as $public) {
            self::assertSame($public, self::clientIp()->of(self::request('203.0.113.7', $public)), $public);
        }
    }

    /** Preflight PF9: a dual-stack socket reports an IPv4 peer as ::ffff:a.b.c.d */
    public function testAnIpv4MappedConnectionIsJudgedAsItsIpv4Address(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        foreach (['::ffff:127.0.0.1', '::ffff:10.1.2.3', '::ffff:192.168.1.1'] as $proxy) {
            self::assertSame('203.0.113.7', self::clientIp()->of(self::request('203.0.113.7', $proxy)), $proxy);
        }
        self::assertSame('::ffff:198.51.100.9', self::clientIp()->of(self::request('203.0.113.7', '::ffff:198.51.100.9')), 'a mapped public address is still public');
    }

    public function testThrottleKeysAnIpv6AddressOnItsSlash64(): void
    {
        self::assertSame('2001:db8:1:2::/64', self::clientIp()->throttleKey(self::request(null, '2001:db8:1:2:aaaa:bbbb:cccc:dddd')));
        self::assertSame('2001:db8:1:2::/64', self::clientIp()->throttleKey(self::request(null, '2001:db8:1:2::9')));
        self::assertSame('198.51.100.9', self::clientIp()->throttleKey(self::request(null, '198.51.100.9')));
        self::assertSame('unknown', self::clientIp()->throttleKey((new ServerRequestFactory())->createServerRequest('POST', '/x')));
    }

    /** Preflight PF9: a mapped IPv4 reader is keyed as that IPv4 address, not as ::/64 with everybody else */
    public function testThrottleKeysAnIpv4MappedAddressAsItsIpv4Address(): void
    {
        self::assertSame('198.51.100.9', self::clientIp()->throttleKey(self::request(null, '::ffff:198.51.100.9')));
        self::assertSame('203.0.113.7', self::clientIp()->throttleKey(self::request(null, '::ffff:203.0.113.7')));
    }

    /** T2-a: a proxy on a public address is trusted only when TRUSTED_PROXIES names it. */
    public function testAListedPublicProxyIsTrusted(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        $_ENV['TRUSTED_PROXIES'] = '198.51.100.0/24, 192.0.2.9';

        self::assertSame('203.0.113.7', self::clientIp()->of(self::request('203.0.113.7', remote: '198.51.100.20')));
        self::assertSame('203.0.113.7', self::clientIp()->of(self::request('203.0.113.7', remote: '192.0.2.9')));
    }

    public function testAnUnlistedPublicProxyIsNotTrusted(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        $_ENV['TRUSTED_PROXIES'] = '198.51.100.0/24';

        self::assertSame('198.51.101.20', self::clientIp()->of(self::request('203.0.113.7', remote: '198.51.101.20')));
        self::assertSame('192.0.2.10', self::clientIp()->of(self::request('203.0.113.7', remote: '192.0.2.10')));
    }

    public function testAnIpv6RangeIsMatchedOnItsPrefix(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        $_ENV['TRUSTED_PROXIES'] = '2001:db8:aa::/48';

        self::assertSame('203.0.113.7', self::clientIp()->of(self::request('203.0.113.7', remote: '2001:db8:aa:ff::1')));
        self::assertSame('2001:db8:ab::1', self::clientIp()->of(self::request('203.0.113.7', remote: '2001:db8:ab::1')));
    }

    public function testMalformedEntriesAreIgnored(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        $_ENV['TRUSTED_PROXIES'] = 'proxy.example, 198.51.100.0/33, 198.51.100.0/abc, /24, 300.1.1.1, 2001:db8::/129, ,198.51.100.5/32';

        self::assertSame('198.51.100.4', self::clientIp()->of(self::request('203.0.113.7', remote: '198.51.100.4')));
        self::assertSame('203.0.113.7', self::clientIp()->of(self::request('203.0.113.7', remote: '198.51.100.5')));
    }

    public function testTheListIsReadFromTheProcessEnvironmentToo(): void
    {
        $_ENV['TRUSTED_PROXY_COUNT'] = '1';
        putenv('TRUSTED_PROXIES=198.51.100.0/24');

        self::assertSame('203.0.113.7', self::clientIp()->of(self::request('203.0.113.7', remote: '198.51.100.20')));
    }

    public function testAListedProxyStillNeedsAProxyCount(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '198.51.100.0/24';

        self::assertSame('198.51.100.20', self::clientIp()->of(self::request('203.0.113.7', remote: '198.51.100.20')));
    }
}
