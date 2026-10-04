<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use App\Telemetry\Telemetry;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\SentrySdk;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Everything here runs with Sentry off: no test ever binds a client. */
final class TelemetryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/release-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/.git/refs/heads', 0o777, true);
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_RELEASE']);
        $_ENV['SENTRY_DSN'] = '';
        @unlink($this->dir . '/.git/refs/heads/master');
        @unlink($this->dir . '/.git/HEAD');
        @rmdir($this->dir . '/.git/refs/heads');
        @rmdir($this->dir . '/.git/refs');
        @rmdir($this->dir . '/.git');
        @rmdir($this->dir);
    }

    public function testWithoutADsnBootBindsNoClient(): void
    {
        $_ENV['SENTRY_DSN'] = '';

        Kernel::boot('/');
        Kernel::boot('/obrok19/');

        self::assertNull(SentrySdk::getCurrentHub()->getClient());
    }

    public function testAppReleaseWins(): void
    {
        $_ENV['APP_RELEASE'] = 'abc1234';
        file_put_contents($this->dir . '/.git/HEAD', str_repeat('f', 40) . "\n");

        self::assertSame('abc1234', Telemetry::release($this->dir));
    }

    public function testWithoutAppReleaseTheBranchHeadIsUsed(): void
    {
        file_put_contents($this->dir . '/.git/HEAD', "ref: refs/heads/master\n");
        file_put_contents($this->dir . '/.git/refs/heads/master', '1b86114' . str_repeat('0', 33) . "\n");

        self::assertSame('1b86114', Telemetry::release($this->dir));
    }

    public function testADetachedHeadIsUsedAsIs(): void
    {
        file_put_contents($this->dir . '/.git/HEAD', '847e29e' . str_repeat('a', 33) . "\n");

        self::assertSame('847e29e', Telemetry::release($this->dir));
    }

    public function testWithNeitherTheReleaseIsUnknown(): void
    {
        self::assertSame('unknown', Telemetry::release($this->dir . '/nowhere'));

        file_put_contents($this->dir . '/.git/HEAD', "ref: refs/heads/gone\n");
        self::assertSame('unknown', Telemetry::release($this->dir), 'a ref file that does not exist (packed refs)');
    }

    public function testCollectingWithoutAClientIsANoOp(): void
    {
        \App\Telemetry\Collector::collect(new \RuntimeException('nobody listens'));

        self::assertNull(SentrySdk::getCurrentHub()->getClient());
    }

    public function testTheTransactionMiddlewareIsAPassThroughWithoutSentry(): void
    {
        $response = (new ResponseFactory())->createResponse(200);
        $response->getBody()->write('<p>beze změny</p>');
        $handler = new class ($response) implements RequestHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;

                return $this->response;
            }
        };

        $result = (new \App\Telemetry\TransactionMiddleware())->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/obrok19/programy'),
            $handler,
        );

        // the very same object: byte-identical to a stack without the middleware
        self::assertSame($response, $result);
        self::assertSame(1, $handler->calls);
    }
}
