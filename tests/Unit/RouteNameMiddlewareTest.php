<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Telemetry\RouteNameMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;

final class RouteNameMiddlewareTest extends TestCase
{
    /** Review Focus 5: the regex stays in, the id and the slug stay out. */
    public function testTheNameIsTheRoutePatternNotThePath(): void
    {
        $app = AppFactory::create();
        $app->setBasePath('/korbo26');
        $route = $app->post('/admin/notify/{id:[0-9]+}/hidden', static fn ($request, $response) => $response);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/korbo26/admin/notify/42/hidden')
            ->withAttribute(RouteContext::ROUTE, $route);

        $name = RouteNameMiddleware::nameFor($request);

        self::assertSame('POST /admin/notify/{id:[0-9]+}/hidden', $name);
        self::assertStringNotContainsString('42', $name);
        self::assertStringNotContainsString('korbo26', $name);
    }

    public function testARequestThatMatchedNoRouteHasNoName(): void
    {
        self::assertNull(RouteNameMiddleware::nameFor((new ServerRequestFactory())->createServerRequest('GET', '/korbo26/nic')));
    }

    public function testWithoutSentryTheRequestPassesStraightThrough(): void
    {
        $response = (new ResponseFactory())->createResponse(204);
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $result = (new RouteNameMiddleware('korbo26'))->process((new ServerRequestFactory())->createServerRequest('GET', '/korbo26/'), $handler);

        self::assertSame($response, $result);
    }
}
