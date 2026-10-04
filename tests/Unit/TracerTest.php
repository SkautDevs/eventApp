<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Telemetry\Tracer;
use PHPUnit\Framework\TestCase;
use Sentry\SentrySdk;

/** Without a bound client — which is every test — a span is just the call. */
final class TracerTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertNull(SentrySdk::getCurrentHub()->getClient(), 'a test must never bind a Sentry client');
    }

    public function testTheCallableRunsOnceAndItsResultIsReturned(): void
    {
        $runs = 0;

        $result = Tracer::span('kissj.list', 'GET v3/programme/list', function () use (&$runs): string {
            $runs++;

            return 'hotovo';
        }, ['rows' => 3, Tracer::FROM_RESULT => static fn (string $r): array => ['length' => strlen($r)]]);

        self::assertSame('hotovo', $result);
        self::assertSame(1, $runs);
    }

    public function testAnExceptionPassesThroughUntouched(): void
    {
        $thrown = new \RuntimeException('kissj down');

        try {
            Tracer::span('kissj.list', 'GET v3/programme/list', static fn () => throw $thrown);
            self::fail('the exception was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame($thrown, $e);
        }
    }

    public function testTaggingWithoutAClientDoesNothing(): void
    {
        Tracer::tag('tie_outcome', 'ok');

        self::assertNull(SentrySdk::getCurrentHub()->getClient());
    }
}
