<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Kernel;
use App\Program\CachingProgramProvider;
use App\Program\ProgramProviderInterface;
use App\Program\KissjProgramProvider;
use App\Program\StubProgramProvider;
use PHPUnit\Framework\TestCase;

final class KernelProviderSelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['PROGRAM_PROVIDER_OBROK27'], $_ENV['PROGRAM_PROVIDER_OBROK19'], $_ENV['KISSJ_BASE_URL'], $_ENV['KISSJ_API_KEY_OBROK27'], $_ENV['PROGRAM_CACHE_TTL'], $_ENV['SESSION_PATH']);
    }

    private function providerFor(): ProgramProviderInterface
    {
        $event = EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok27');
        $app = Kernel::create($event);

        return $app->getContainer()->get(ProgramProviderInterface::class);
    }

    public function testDefaultIsStub(): void
    {
        self::assertInstanceOf(StubProgramProvider::class, $this->providerFor());
    }

    public function testKissjSelectedBehindTheCache(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'kissj';
        $_ENV['KISSJ_BASE_URL'] = 'https://kissj.example';
        $_ENV['KISSJ_API_KEY_OBROK27'] = 'secret-key';

        $provider = $this->providerFor();

        self::assertInstanceOf(CachingProgramProvider::class, $provider);
        self::assertInstanceOf(KissjProgramProvider::class, $provider->inner());
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

    public function testKissjWithoutBaseUrlThrows(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'kissj';
        $_ENV['KISSJ_BASE_URL'] = '';
        $_ENV['KISSJ_API_KEY_OBROK27'] = 'secret-key';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('KISSJ_BASE_URL');
        $this->providerFor();
    }

    /**
     * The event is resolved from the key, so without one kissj cannot tell which event
     * is asking — every request would be a 401. That is a misconfiguration to stop at
     * boot, not an outage for the Program screen to paper over.
     */
    public function testKissjWithoutApiKeyThrows(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK27'] = 'kissj';
        $_ENV['KISSJ_BASE_URL'] = 'https://kissj.example';
        $_ENV['KISSJ_API_KEY_OBROK27'] = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PROGRAM_PROVIDER=kissj requires KISSJ_API_KEY_OBROK27');
        $this->providerFor();
    }

    public function testAnotherEventsSettingDoesNotApply(): void
    {
        $_ENV['PROGRAM_PROVIDER_OBROK19'] = 'kissj';
        self::assertInstanceOf(StubProgramProvider::class, $this->providerFor());
        unset($_ENV['PROGRAM_PROVIDER_OBROK19']);
    }
}
