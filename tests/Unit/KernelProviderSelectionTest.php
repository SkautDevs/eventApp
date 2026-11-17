<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Kernel;
use App\Program\CachingProgramProvider;
use App\Program\ProgramProviderInterface;
use App\Program\KissjProgramProvider;
use App\Program\StubProgramProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KernelProviderSelectionTest extends TestCase
{
    /**
     * The retired instance-wide kissj URL. Spelled in parts so that a search of the tree
     * for the old name finds no live reference; this test only proves it is ignored.
     */
    private const string RETIRED_BASE_URL = 'KISSJ_' . 'BASE_URL';

    protected function tearDown(): void
    {
        // tests/bootstrap.php pins every event to local; put that back rather than unsetting
        foreach (['PROGRAM_PROVIDER_OBROK27', 'PROGRAM_PROVIDER_OBROK19'] as $name) {
            $_ENV[$name] = 'local';
            putenv($name . '=local');
        }
        unset($_ENV[self::RETIRED_BASE_URL], $_ENV['KISSJ_API_KEY_OBROK27'], $_ENV['PROGRAM_CACHE_TTL'], $_ENV['SESSION_PATH']);
        putenv(self::RETIRED_BASE_URL);
    }

    private function event(): EventConfig
    {
        return EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok27');
    }

    private function providerFor(): ProgramProviderInterface
    {
        return Kernel::create($this->event())->getContainer()->get(ProgramProviderInterface::class);
    }

    public function testLocalIsTheFixtureFiles(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'local';

        self::assertNull(Kernel::programSource($this->event()));
        self::assertInstanceOf(StubProgramProvider::class, $this->providerFor());
    }

    public function testAUrlIsKissjBehindTheCache(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'https://kissj.example';
        $_ENV['KISSJ_API_KEY_OBROK27'] = 'secret-key';

        $provider = $this->providerFor();

        self::assertInstanceOf(CachingProgramProvider::class, $provider);
        self::assertInstanceOf(KissjProgramProvider::class, $provider->inner());
    }

    public function testTheSourceIsTheUrlWithoutATrailingSlash(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'http://localhost:8080/';
        $_ENV['KISSJ_API_KEY_OBROK27'] = 'secret-key';

        self::assertSame('http://localhost:8080', Kernel::programSource($this->event()));
    }

    /**
     * No event falls back to the fixtures: demo data shown as if it were real is worse
     * than an event that does not boot. EventConfig::env() falls back to getenv, so the
     * process environment is cleared too.
     */
    public function testAMissingValueStopsTheEventAtCreate(): void
    {
        unset($_ENV['PROGRAM_PROVIDER_OBROK27']);
        putenv('PROGRAM_PROVIDER_OBROK27');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PROGRAM_PROVIDER_OBROK27');
        // create() alone, no get(): the variable is checked when the event boots
        Kernel::create($this->event());
    }

    #[DataProvider('invalidSources')]
    public function testAnythingButLocalOrAnHttpUrlStopsTheEventAtCreate(string $value): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = $value;
        $_ENV['KISSJ_API_KEY_OBROK27'] = 'secret-key';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PROGRAM_PROVIDER_OBROK27');
        Kernel::create($this->event());
    }

    /** @return array<string, array{string}> */
    public static function invalidSources(): array
    {
        return [
            'stub' => ['stub'],
            'kissj' => ['kissj'],
            'a host without a scheme' => ['kissj.example'],
            'another scheme' => ['ftp://kissj.example'],
        ];
    }

    /**
     * The event is resolved from the key, so without one kissj cannot tell which event
     * is asking — every request would be a 401. That is a misconfiguration to stop at
     * boot, not an outage for the Program screen to paper over.
     */
    public function testAUrlWithoutApiKeyStopsTheEventAtCreate(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'https://kissj.example';
        $_ENV['KISSJ_API_KEY_OBROK27'] = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('KISSJ_API_KEY_OBROK27');
        Kernel::create($this->event());
    }

    public function testAnotherEventsSettingDoesNotApply(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK19'] = 'https://kissj.example';

        self::assertInstanceOf(StubProgramProvider::class, $this->providerFor());
    }

    public function testTheRetiredBaseUrlIsReadNowhere(): void
    {
        $_ENV[self::RETIRED_BASE_URL] = 'https://elsewhere.example';
        putenv(self::RETIRED_BASE_URL . '=https://elsewhere.example');

        self::assertInstanceOf(StubProgramProvider::class, $this->providerFor());

        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'https://kissj.example';
        $_ENV['KISSJ_API_KEY_OBROK27'] = 'secret-key';
        self::assertSame('https://kissj.example', Kernel::programSource($this->event()));
    }

    public function testTheCacheTtlIsWholeSecondsAndFallsBackTo300(): void
    {
        foreach ([[null, 300], ['', 300], ['0', 0], ['60', 60], ['-5', 300], ['5m', 300], [' 60', 300], ['1.5', 300]] as [$value, $expected]) {
            if ($value === null) {
                unset($_ENV['PROGRAM_CACHE_TTL']);
            } else {
                $_ENV['PROGRAM_CACHE_TTL'] = $value;
            }

            self::assertSame($expected, Kernel::programCacheTtl(), var_export($value, true));
        }
    }

    public function testTheSessionStoreIsVarSessionsUnlessSessionPathSaysOtherwise(): void
    {
        self::assertSame('/app/var/sessions', Kernel::sessionPath('/app'));

        $_ENV['SESSION_PATH'] = '';
        self::assertSame('/app/var/sessions', Kernel::sessionPath('/app'));

        $_ENV['SESSION_PATH'] = 'var/elsewhere';
        self::assertSame('/app/var/elsewhere', Kernel::sessionPath('/app'));

        $_ENV['SESSION_PATH'] = '/tmp/eventapp-test-sessions';
        self::assertSame('/tmp/eventapp-test-sessions', Kernel::sessionPath('/app'));
    }
}
