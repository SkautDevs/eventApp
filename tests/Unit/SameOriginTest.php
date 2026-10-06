<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\SameOrigin;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SameOriginTest extends TestCase
{
    private static function post(array $headers, string $uri = 'https://obrok.example/obrok27/profil/tie'): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function testSecFetchSiteDecidesWhenPresent(): void
    {
        self::assertTrue(SameOrigin::allows(self::post(['Sec-Fetch-Site' => 'same-origin'])));
        self::assertTrue(SameOrigin::allows(self::post(['Sec-Fetch-Site' => 'none'])));
        self::assertFalse(SameOrigin::allows(self::post(['Sec-Fetch-Site' => 'cross-site'])));
        self::assertFalse(SameOrigin::allows(self::post(['Sec-Fetch-Site' => 'same-site'])));
        // a browser that says same-origin is believed even behind a proxy that rewrote Host
        self::assertTrue(SameOrigin::allows(self::post(['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://elsewhere.example'])));
    }

    public function testWithoutSecFetchSiteTheOriginsHostMustMatch(): void
    {
        self::assertTrue(SameOrigin::allows(self::post(['Origin' => 'https://obrok.example'])));
        self::assertFalse(SameOrigin::allows(self::post(['Origin' => 'https://evil.example'])));
        self::assertFalse(SameOrigin::allows(self::post(['Origin' => 'null'])));
    }

    /** Review Focus 5 */
    public function testAnOriginWithAPortOnTheSameHostIsAllowed(): void
    {
        self::assertTrue(SameOrigin::allows(self::post(['Origin' => 'http://localhost:8081'], 'http://localhost:8081/korbo26/profil/tie')));
    }

    public function testNeitherHeaderIsNotABrowserFormAndPasses(): void
    {
        self::assertTrue(SameOrigin::allows(self::post([])));
    }
}
