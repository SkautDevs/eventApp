<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\QuietErrorHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\CallableResolver;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class QuietErrorHandlerTest extends TestCase
{
    private TestHandler $log;

    private QuietErrorHandler $handler;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
        $this->handler = new QuietErrorHandler(new CallableResolver(), new ResponseFactory(), new Logger('errors', [$this->log]));
    }

    public function testARealErrorIsLoggedToTheErrorsChannel(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/obrok19/programy');

        $response = ($this->handler)($request, new \RuntimeException('kissj exploded'), false, true, true);

        self::assertSame(500, $response->getStatusCode());
        self::assertTrue($this->log->hasErrorThatContains('kissj exploded'));
        self::assertSame('errors', $this->log->getRecords()[0]->channel);
    }

    public function testANotFoundIsNotLogged(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/obrok19/neexistuje');

        $response = ($this->handler)($request, new HttpNotFoundException($request), false, true, true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->log->getRecords());
    }
}
