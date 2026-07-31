<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Kernel;
use App\Program\ProgramProviderInterface;
use App\Program\KissjProgramProvider;
use App\Program\StubProgramProvider;
use PHPUnit\Framework\TestCase;

final class KernelProviderSelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['PROGRAM_PROVIDER'], $_ENV['KISSJ_BASE_URL']);
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

    public function testKissjSelected(): void
    {
        $_ENV['PROGRAM_PROVIDER'] = 'kissj';
        $_ENV['KISSJ_BASE_URL'] = 'https://kissj.example/api/';

        self::assertInstanceOf(KissjProgramProvider::class, $this->providerFor());
    }

    public function testKissjWithoutBaseUrlThrows(): void
    {
        $_ENV['PROGRAM_PROVIDER'] = 'kissj';
        $_ENV['KISSJ_BASE_URL'] = '';

        $this->expectException(\RuntimeException::class);
        $this->providerFor();
    }
}
