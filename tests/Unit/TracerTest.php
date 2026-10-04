<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Telemetry\Tracer;
use App\Auth\UnknownParticipantException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
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

        $caught = null;
        try {
            Tracer::span('kissj.list', 'GET v3/programme/list', static fn () => throw $thrown);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertSame($thrown, $caught, 'the exception was swallowed or replaced');
    }

    public function testTaggingWithoutAClientDoesNothing(): void
    {
        Tracer::tag('tie_outcome', 'ok');

        self::assertNull(SentrySdk::getCurrentHub()->getClient());
    }

    /** kissj's 404 for an unknown code is an answer, not a fault, and the span should say so. */
    public function testAnHttpAnswerKeepsItsMeaningInTheSpanStatus(): void
    {
        $request = new Request('GET', 'v3/programme/participant/tie/x');

        self::assertSame('not_found', (string) Tracer::statusFor(new ClientException('Not Found', $request, new Response(404))));
        self::assertSame('unauthenticated', (string) Tracer::statusFor(new ClientException('Unauthorized', $request, new Response(401))));
        self::assertSame('internal_error', (string) Tracer::statusFor(new ServerException('Server Error', $request, new Response(500))));
    }

    public function testAFailureWithoutAnAnswerIsAnInternalError(): void
    {
        self::assertSame('internal_error', (string) Tracer::statusFor(new ConnectException('down', new Request('GET', 'x'))));
        self::assertSame('internal_error', (string) Tracer::statusFor(new \RuntimeException('kissj down')));
    }

    public function testAnUnknownParticipantIsNotFound(): void
    {
        self::assertSame('not_found', (string) Tracer::statusFor(new UnknownParticipantException('Unknown TIE code')));
    }

    public function testTaggingTheSpanWithoutAClientDoesNothing(): void
    {
        Tracer::spanTag('push.outcome', 'delivered');

        self::assertNull(SentrySdk::getCurrentHub()->getClient());
    }
}
