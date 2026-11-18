<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Environment;
use PHPUnit\Framework\TestCase;

/** One case per reader of App\Environment, with the values the Kernel statics' tests used. */
final class EnvironmentReadTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved;

    protected function setUp(): void
    {
        $this->saved = $_ENV;
    }

    protected function tearDown(): void
    {
        // tests/bootstrap.php's VAPID pair and the local providers live in $_ENV too
        $_ENV = $this->saved;
    }

    public function testASnapshotDoesNotFollowLaterChanges(): void
    {
        $_ENV['PROGRAM_CACHE_TTL'] = '60';
        $environment = Environment::fromGlobals();
        $_ENV['PROGRAM_CACHE_TTL'] = '5';
        self::assertSame(60, $environment->programCacheTtl());
    }

    public function testAnEventVariableFallsBackToTheProcessEnvironmentAndAGlobalOneDoesNot(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK27'], $_ENV['APP_DEBUG']);
        putenv('ADMIN_TOKEN_OBROK27=from-process');
        putenv('APP_DEBUG=1');
        try {
            $environment = Environment::fromGlobals();
            self::assertSame('from-process', $environment->forEvent('obrok27', 'ADMIN_TOKEN'));
            self::assertFalse($environment->debug());
        } finally {
            // a variable left in the process would reach every later test through the same fallback
            putenv('ADMIN_TOKEN_OBROK27');
            putenv('APP_DEBUG');
        }
    }

    /**
     * Under Apache mod_php a SetEnv variable is visible only to getenv($key), never to the
     * snapshot; a key missing from both snapshots is therefore read live. Under the CLI a
     * putenv() after the snapshot stands in for it.
     */
    public function testAnEventVariableAndTheProxyListMissingFromBothSnapshotsAreReadLive(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK27'], $_ENV['TRUSTED_PROXIES']);
        $environment = Environment::fromGlobals();
        putenv('ADMIN_TOKEN_OBROK27=from-sapi');
        putenv('TRUSTED_PROXIES=198.51.100.0/24');
        try {
            self::assertSame('from-sapi', $environment->forEvent('obrok27', 'ADMIN_TOKEN'));
            self::assertSame(['198.51.100.0/24'], $environment->trustedProxies());
        } finally {
            putenv('ADMIN_TOKEN_OBROK27');
            putenv('TRUSTED_PROXIES');
        }
    }

    public function testGetReadsTheVariableAsSetOrTheDefault(): void
    {
        unset($_ENV['SENTRY_DSN']);
        self::assertSame('', Environment::fromGlobals()->get('SENTRY_DSN'));
        self::assertSame('fallback', Environment::fromGlobals()->get('SENTRY_DSN', 'fallback'));

        $_ENV['SENTRY_DSN'] = 'https://public@sentry.example/1';
        self::assertSame('https://public@sentry.example/1', Environment::fromGlobals()->get('SENTRY_DSN', 'fallback'));
    }

    /** Moved from EventConfigTest::testEnvFallsBackToTheProcessEnvironment. */
    public function testForEventFallsBackToTheProcessEnvironment(): void
    {
        putenv('ADMIN_TOKEN_MINIMAL=from-process');
        putenv('ADMIN_TOKEN=instance-wide');
        try {
            self::assertSame('from-process', Environment::fromGlobals()->forEvent('minimal', 'ADMIN_TOKEN'));
            putenv('ADMIN_TOKEN_MINIMAL');
            self::assertSame('', Environment::fromGlobals()->forEvent('minimal', 'ADMIN_TOKEN'));
        } finally {
            putenv('ADMIN_TOKEN_MINIMAL');
            putenv('ADMIN_TOKEN');
        }
    }

    /** Moved from EventConfigTest::testEnvKeyIsSuffixedWithTheUpperCasedSlug. */
    public function testEventKeyIsSuffixedWithTheUpperCasedSlug(): void
    {
        self::assertSame('ADMIN_TOKEN_MINIMAL', Environment::eventKey('minimal', 'ADMIN_TOKEN'));
        self::assertSame('PROGRAM_PROVIDER_OBROK27', Environment::eventKey('obrok27', 'PROGRAM_PROVIDER'));
        self::assertSame('PROGRAM_PROVIDER_MAP_EMBED', Environment::eventKey('map-embed', 'PROGRAM_PROVIDER'));
    }

    /** Moved from EventConfigTest::testEnvReadsTheSuffixedVariableOnly. */
    public function testForEventReadsTheSuffixedVariableOnly(): void
    {
        $_ENV['ADMIN_TOKEN'] = 'instance-wide';
        $_ENV['ADMIN_TOKEN_MINIMAL'] = 'per-event';
        self::assertSame('per-event', Environment::fromGlobals()->forEvent('minimal', 'ADMIN_TOKEN'));
        unset($_ENV['ADMIN_TOKEN_MINIMAL']);
        self::assertSame('fallback', Environment::fromGlobals()->forEvent('minimal', 'ADMIN_TOKEN', 'fallback'));
    }

    public function testDebugIsAWhitelist(): void
    {
        foreach ([[null, false], ['', false], ['1', true], ['true', true], ['on', true], ['false', false], ['off', false], ['0', false], ['yes', false]] as [$value, $expected]) {
            if ($value === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $value;
            }

            self::assertSame($expected, Environment::fromGlobals()->debug(), var_export($value, true));
        }
    }

    public function testTheCacheTtlIsWholeSecondsAndFallsBackTo300(): void
    {
        foreach ([[null, 300], ['', 300], ['0', 0], ['60', 60], ['-5', 300], ['5m', 300], [' 60', 300], ['1.5', 300]] as [$value, $expected]) {
            if ($value === null) {
                unset($_ENV['PROGRAM_CACHE_TTL']);
            } else {
                $_ENV['PROGRAM_CACHE_TTL'] = $value;
            }

            self::assertSame($expected, Environment::fromGlobals()->programCacheTtl(), var_export($value, true));
        }
    }

    public function testThePushDatabaseIsVarPushSqliteUnlessPushDbPathSaysOtherwise(): void
    {
        unset($_ENV['PUSH_DB_PATH']);
        self::assertSame('/app/var/push.sqlite', Environment::fromGlobals()->pushDbPath('/app'));

        $_ENV['PUSH_DB_PATH'] = 'var/elsewhere.sqlite';
        self::assertSame('/app/var/elsewhere.sqlite', Environment::fromGlobals()->pushDbPath('/app'));

        $_ENV['PUSH_DB_PATH'] = '/tmp/eventapp-test.sqlite';
        self::assertSame('/tmp/eventapp-test.sqlite', Environment::fromGlobals()->pushDbPath('/app'));
    }

    public function testTheSessionStoreIsVarSessionsUnlessSessionPathSaysOtherwise(): void
    {
        unset($_ENV['SESSION_PATH']);
        self::assertSame('/app/var/sessions', Environment::fromGlobals()->sessionPath('/app'));

        $_ENV['SESSION_PATH'] = '';
        self::assertSame('/app/var/sessions', Environment::fromGlobals()->sessionPath('/app'));

        $_ENV['SESSION_PATH'] = 'var/elsewhere';
        self::assertSame('/app/var/elsewhere', Environment::fromGlobals()->sessionPath('/app'));

        $_ENV['SESSION_PATH'] = '/tmp/eventapp-test-sessions';
        self::assertSame('/tmp/eventapp-test-sessions', Environment::fromGlobals()->sessionPath('/app'));
    }

    public function testTheStubStateIsVarStubUnlessStubStatePathSaysOtherwise(): void
    {
        unset($_ENV['STUB_STATE_PATH']);
        self::assertSame('/app/var/stub', Environment::fromGlobals()->stubStatePath('/app'));

        $_ENV['STUB_STATE_PATH'] = '';
        self::assertSame('/app/var/stub', Environment::fromGlobals()->stubStatePath('/app'));

        $_ENV['STUB_STATE_PATH'] = 'var/elsewhere';
        self::assertSame('/app/var/elsewhere', Environment::fromGlobals()->stubStatePath('/app'));

        $_ENV['STUB_STATE_PATH'] = '/tmp/eventapp-test-stub';
        self::assertSame('/tmp/eventapp-test-stub', Environment::fromGlobals()->stubStatePath('/app'));
    }

    public function testLocalIsNoProgramSource(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'local';

        self::assertNull(Environment::fromGlobals()->programSource('obrok27'));
    }

    public function testTheProgramSourceIsTheUrlWithoutATrailingSlash(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'http://localhost:8080/';
        $_ENV['KISSJ_API_KEY_OBROK27'] = 'secret-key';

        self::assertSame('http://localhost:8080', Environment::fromGlobals()->programSource('obrok27'));
    }

    public function testAnythingButLocalOrAnHttpUrlNamesTheVariable(): void
    {
        $_ENV['KISSJ_API_KEY_OBROK27'] = 'secret-key';
        foreach (['stub', 'kissj', 'kissj.example', 'ftp://kissj.example'] as $value) {
            $_ENV['PROGRAM_PROVIDER_OBROK27'] = $value;
            try {
                Environment::fromGlobals()->programSource('obrok27');
                self::fail($value . ' was accepted');
            } catch (\RuntimeException $e) {
                self::assertSame('PROGRAM_PROVIDER_OBROK27 must be "local" or the http(s) root URL of a kissj instance', $e->getMessage(), $value);
            }
        }
    }

    public function testAUrlWithoutApiKeyNamesBothVariables(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'https://kissj.example';
        $_ENV['KISSJ_API_KEY_OBROK27'] = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PROGRAM_PROVIDER_OBROK27 is a kissj URL and requires KISSJ_API_KEY_OBROK27');
        Environment::fromGlobals()->programSource('obrok27');
    }

    public function testVapidIsTheThreeVariablesAsSet(): void
    {
        $_ENV['VAPID_PUBLIC_KEY'] = 'public';
        $_ENV['VAPID_PRIVATE_KEY'] = 'private';
        $_ENV['VAPID_SUBJECT'] = 'mailto:tests@example.invalid';
        self::assertSame(
            ['public' => 'public', 'private' => 'private', 'subject' => 'mailto:tests@example.invalid'],
            Environment::fromGlobals()->vapid(),
        );

        unset($_ENV['VAPID_PUBLIC_KEY'], $_ENV['VAPID_PRIVATE_KEY'], $_ENV['VAPID_SUBJECT']);
        self::assertSame(['public' => '', 'private' => '', 'subject' => ''], Environment::fromGlobals()->vapid());
    }

    public function testPushEndpointHostsIsTheCommaSeparatedList(): void
    {
        unset($_ENV['PUSH_ENDPOINT_HOSTS']);
        self::assertSame([], Environment::fromGlobals()->pushEndpointHosts());

        $_ENV['PUSH_ENDPOINT_HOSTS'] = 'push.example, *.dev.example';
        self::assertSame(['push.example', '*.dev.example'], Environment::fromGlobals()->pushEndpointHosts());
    }

    public function testTheProxyCountIsWholeHopsAndOtherwiseZero(): void
    {
        foreach ([[null, 0], ['', 0], ['1', 1], ['3', 3], ['yes', 0], ['-1', 0]] as [$value, $expected]) {
            if ($value === null) {
                unset($_ENV['TRUSTED_PROXY_COUNT']);
            } else {
                $_ENV['TRUSTED_PROXY_COUNT'] = $value;
            }

            self::assertSame($expected, Environment::fromGlobals()->trustedProxyCount(), var_export($value, true));
        }
    }

    public function testTrustedProxiesIsTheCommaSeparatedListAndFallsBackToTheProcessEnvironment(): void
    {
        unset($_ENV['TRUSTED_PROXIES']);
        self::assertSame([], Environment::fromGlobals()->trustedProxies());

        $_ENV['TRUSTED_PROXIES'] = '198.51.100.0/24, 192.0.2.9, ,';
        self::assertSame(['198.51.100.0/24', '192.0.2.9'], Environment::fromGlobals()->trustedProxies());

        unset($_ENV['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES=198.51.100.0/24');
        try {
            self::assertSame(['198.51.100.0/24'], Environment::fromGlobals()->trustedProxies());
        } finally {
            putenv('TRUSTED_PROXIES');
        }
    }
}
