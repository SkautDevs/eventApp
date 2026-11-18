<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tools\Gate\Normalizer;

/** Each of the gate's normaliser rows replaces its value in context and nothing next to it. */
final class GateNormalizerTest extends TestCase
{
    private const string SAMPLE = <<<'TXT'
        HTTP/1.1 200 OK
        Content-Security-Policy: script-src 'self' 'nonce-0123abcd'
        Set-Cookie: eventapp=k3j4h5; expires=Sat, 19 Jun 2027 08:00:00 GMT; Max-Age=2592000; path=/

        <meta name="vapid-public-key" content="BPx-abc_def">
        <link rel="stylesheet" href="/style.css?v=0a1b2c3d">
        <script type="module" src="/js/0a1b2c3d/app.js"></script>
        <script nonce="0123abcd">x</script>
        <input type="hidden" name="csrf" value="ff00">
        <input type="hidden" name="nonce" value="ee11">
        TXT;

    public function testAnAssetHashIsReplacedOnlyAfterAScriptOrStylesheetName(): void
    {
        self::assertSame('<link href="style.css?v=H">', Normalizer::normalize('<link href="style.css?v=0a1b2c3d">'));
        self::assertSame('<script src="app.js?v=H">', Normalizer::normalize('<script src="app.js?v=0a1b2c3d">'));
        // a colour and a programme id are not hashes
        self::assertSame('color: #0a1b2c3d;', Normalizer::normalize('color: #0a1b2c3d;'));
        self::assertSame('?v=0a1b2c3d', Normalizer::normalize('?v=0a1b2c3d'));
        self::assertSame('logo.png?v=0a1b2c3d', Normalizer::normalize('logo.png?v=0a1b2c3d'));
    }

    public function testTheNonceIsReplacedInTheHeaderAndInMarkup(): void
    {
        self::assertSame("script-src 'self' 'nonce-N'", Normalizer::normalize("script-src 'self' 'nonce-q83vEjRW2Ax='"));
        self::assertSame('<script nonce="N">', Normalizer::normalize('<script nonce="q83vEjRW2Ax=">'));
        // the send form's field is not a CSP nonce and keeps its own rule
        self::assertSame('name="nonce" value="C"', Normalizer::normalize('name="nonce" value="abc123"'));
        // neither an unquoted source nor another attribute ending in nonce
        self::assertSame('nonce-abc123', Normalizer::normalize('nonce-abc123'));
        self::assertSame('<p data-nonce="abc123">', Normalizer::normalize('<p data-nonce="abc123">'));
    }

    public function testTheSessionCookieAndItsExpiryAreReplacedInSetCookieOnly(): void
    {
        self::assertSame(
            'Set-Cookie: eventapp=S; expires=E; Max-Age=2592000; path=/',
            Normalizer::normalize('Set-Cookie: eventapp=k3j4h5; expires=Sat, 19 Jun 2027 08:00:00 GMT; Max-Age=2592000; path=/'),
        );
        // another cookie, and the name in a body, are left alone
        self::assertSame(
            'Set-Cookie: other=k3j4h5; expires=Sat, 19 Jun 2027 08:00:00 GMT',
            Normalizer::normalize('Set-Cookie: other=k3j4h5; expires=Sat, 19 Jun 2027 08:00:00 GMT'),
        );
        self::assertSame('<p>eventapp=k3j4h5</p>', Normalizer::normalize('<p>eventapp=k3j4h5</p>'));
    }

    public function testTheCsrfAndFormNonceFieldsAreReplaced(): void
    {
        self::assertSame('<input type="hidden" name="csrf" value="C">', Normalizer::normalize('<input type="hidden" name="csrf" value="ff00">'));
        self::assertSame('<input type="hidden" name="nonce" value="C">', Normalizer::normalize('<input type="hidden" name="nonce" value="ee11">'));
        // the log's own hidden fields are not secrets
        self::assertSame('<input type="hidden" name="page" value="1">', Normalizer::normalize('<input type="hidden" name="page" value="1">'));
    }

    public function testTheVapidKeyIsReplacedInItsMetaOnly(): void
    {
        self::assertSame('<meta name="vapid-public-key" content="V">', Normalizer::normalize('<meta name="vapid-public-key" content="BPx-abc_def">'));
        self::assertSame('<meta name="push-identity" content="1">', Normalizer::normalize('<meta name="push-identity" content="1">'));
    }

    public function testTheScriptDirectoryHashIsReplaced(): void
    {
        self::assertSame('<script src="/js/H/app.js">', Normalizer::normalize('<script src="/js/0a1b2c3d/app.js">'));
        // another directory, and a name under /js/ that is not a hash
        self::assertSame('/css/0a1b2c3d/style.css', Normalizer::normalize('/css/0a1b2c3d/style.css'));
        self::assertSame('/js/app.js', Normalizer::normalize('/js/app.js'));
        self::assertSame('/js/0a1b2c3dee/', Normalizer::normalize('/js/0a1b2c3dee/'));
    }

    public function testNormalizingTwiceChangesNothingMore(): void
    {
        $once = Normalizer::normalize(self::SAMPLE);
        self::assertNotSame(self::SAMPLE, $once);
        self::assertSame($once, Normalizer::normalize($once));
    }
}
