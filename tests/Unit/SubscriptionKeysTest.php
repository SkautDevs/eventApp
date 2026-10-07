<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\SubscriptionKeys;
use PHPUnit\Framework\TestCase;
use Tests\Functional\AppTestCase;

final class SubscriptionKeysTest extends TestCase
{
    /** 0x04 + x=1, y=1: the right length and prefix, but not a point on P-256 (verified: OpenSSL refuses it) */
    public const string OFF_CURVE_PUBLIC = 'BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAE';

    public function testARealBrowserKeyPairIsValid(): void
    {
        self::assertTrue(SubscriptionKeys::valid(AppTestCase::BROWSER_KEYS['p256dh'], AppTestCase::BROWSER_KEYS['auth']));
    }

    public function testAPointOffTheCurveIsInvalid(): void
    {
        self::assertFalse(SubscriptionKeys::valid(self::OFF_CURVE_PUBLIC, AppTestCase::BROWSER_KEYS['auth']));
    }

    public function testShapesThatAreNotKeysAreInvalid(): void
    {
        $auth = AppTestCase::BROWSER_KEYS['auth'];
        foreach (['', 'PK', 'test-key', str_repeat('A', 87), 123, null] as $p256dh) {
            self::assertFalse(SubscriptionKeys::valid($p256dh, $auth), var_export($p256dh, true));
        }
        foreach (['', 'AT', rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '='), null] as $bad) {
            self::assertFalse(SubscriptionKeys::valid(AppTestCase::BROWSER_KEYS['p256dh'], $bad));
        }
    }
}
