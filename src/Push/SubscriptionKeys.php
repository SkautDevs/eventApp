<?php

declare(strict_types=1);

namespace App\Push;

/**
 * A browser subscription's keys, checked the way the encryption will use them. The length
 * and the 0x04 prefix are not enough: a 65-byte value that is not a point on P-256 passes
 * both and makes the library's ECDH throw inside flush(). OpenSSL is asked to parse the key
 * as an EC public key, which it refuses for a point off the curve.
 */
final class SubscriptionKeys
{
    /** SubjectPublicKeyInfo header for an uncompressed P-256 point (id-ecPublicKey, prime256v1). */
    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function valid(mixed $p256dh, mixed $auth): bool
    {
        if (!is_string($p256dh) || !is_string($auth)) {
            return false;
        }
        $point = self::base64Url($p256dh);
        $secret = self::base64Url($auth);

        return $point !== null && strlen($point) === 65 && $point[0] === "\x04"
            && $secret !== null && strlen($secret) === 16
            && self::onCurve($point);
    }

    private static function onCurve(string $point): bool
    {
        $der = (string) hex2bin(self::SPKI_P256_PREFIX) . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        // a refused key leaves messages in OpenSSL's queue; drain them so nothing later reads them
        while (openssl_error_string() !== false) {
        }

        return $key !== false;
    }

    private static function base64Url(string $value): ?string
    {
        $decoded = $value === '' ? false : base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
