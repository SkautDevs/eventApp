<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Authenticator;
use App\Auth\PairingState;
use App\Http\ScreenAliasMiddleware;
use App\Http\ScreenMiddleware;
use App\Http\SecurityHeadersMiddleware;
use App\Http\SessionCookieMiddleware;
use App\Program\Freshness;
use App\Session;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Tests\FixedClock;

/**
 * The three middlewares outside the error middleware, each on its own, and the alias
 * middleware inside the routing middleware that renames a view address's screen.
 */
final class ShellMiddlewareTest extends TestCase
{
    public function testSecurityHeadersAreAddedAndARoutesOwnValueIsKept(): void
    {
        $response = new SecurityHeadersMiddleware()->process(
            $this->request('/'),
            $this->handler(new ResponseFactory()->createResponse()->withHeader('Referrer-Policy', 'no-referrer')),
        );

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('geolocation=(), camera=(), microphone=(), payment=()', $response->getHeaderLine('Permissions-Policy'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertCount(4, SecurityHeadersMiddleware::HEADERS);
    }

    public function testTheScreenMiddlewareSetsTheFragmentGlobalsAndThePagePolicy(): void
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $twig->getEnvironment()->addGlobal('fragment', false);
        $twig->getEnvironment()->addGlobal('screenPath', '/');
        $now = new \DateTimeImmutable('2027-05-21T09:00:00+02:00');
        $freshness = new Freshness(new FixedClock(new \DateTimeImmutable('2020-01-01T00:00:00+01:00')));
        $middleware = new ScreenMiddleware($twig, $freshness, new FixedClock($now));

        $response = $middleware->process(
            $this->request('/obrok19/programy')->withHeader('X-Screen', '1'),
            $this->handler(new ResponseFactory()->createResponse()->withHeader('Content-Type', 'text/html; charset=utf-8')),
        );

        $globals = $twig->getEnvironment()->getGlobals();
        self::assertTrue($globals['fragment']);
        self::assertSame('/obrok19/programy', $globals['screenPath']);
        self::assertSame('X-Screen', $response->getHeaderLine('Vary'));
        self::assertSame(ScreenMiddleware::PAGE_CACHE_CONTROL, $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-store, no-cache, must-revalidate', ScreenMiddleware::PAGE_CACHE_CONTROL);
        self::assertSame($now->format(DATE_ATOM), $freshness->forView()->fetchedAt);
    }

    public function testTheScreenMiddlewareKeepsARoutesOwnPolicyAndLeavesJsonAlone(): void
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $clock = new FixedClock(new \DateTimeImmutable('2027-05-21T09:00:00+02:00'));
        $middleware = new ScreenMiddleware($twig, new Freshness($clock), $clock);

        $own = $middleware->process($this->request('/'), $this->handler(new ResponseFactory()->createResponse()->withHeader('Cache-Control', 'no-store')));
        $json = $middleware->process($this->request('/'), $this->handler(new ResponseFactory()->createResponse()->withHeader('Content-Type', 'application/json')));

        self::assertSame('no-store', $own->getHeaderLine('Cache-Control'));
        self::assertFalse($json->hasHeader('Cache-Control'));
        self::assertSame('X-Screen', $json->getHeaderLine('Vary'));
        self::assertFalse($twig->getEnvironment()->getGlobals()['fragment']);
    }

    public function testScreenMiddlewareSetsThePageAddressWithItsQuery(): void
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $twig->getEnvironment()->addGlobal('screenPath', '/');
        $twig->getEnvironment()->addGlobal('pageAddress', '/');
        $clock = new FixedClock(new \DateTimeImmutable('2027-05-21T09:00:00+02:00'));
        $middleware = new ScreenMiddleware($twig, new Freshness($clock), $clock);

        $middleware->process($this->request('/x/programy?den=20260916'), $this->handler(new ResponseFactory()->createResponse()));
        $globals = $twig->getEnvironment()->getGlobals();
        self::assertSame('/x/programy', $globals['screenPath']);
        self::assertSame('/x/programy?den=20260916', $globals['pageAddress']);

        // no query, no question mark
        $middleware->process($this->request('/x/programy'), $this->handler(new ResponseFactory()->createResponse()));
        self::assertSame('/x/programy', $twig->getEnvironment()->getGlobals()['pageAddress']);
    }

    /**
     * Through a real Slim app, because the route is only known inside the routing
     * middleware: a route that names its screen renames screenPath, one that does not
     * leaves whatever ScreenMiddleware set.
     */
    public function testTheAliasMiddlewareNamesTheRoutesScreen(): void
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $twig->getEnvironment()->addGlobal('screenPath', '/untouched');
        $app = AppFactory::create();
        $app->setBasePath('/x');
        $ok = fn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response;
        $app->get('/programy/moje', $ok)->setName('programs-mine')->setArgument('screen', '/programy');
        $app->get('/informace', $ok);
        $app->add(new ScreenAliasMiddleware($twig));
        $app->addRoutingMiddleware();

        $app->handle($this->request('/x/informace'));
        self::assertSame('/untouched', $twig->getEnvironment()->getGlobals()['screenPath']);

        $app->handle($this->request('/x/programy/moje'));
        self::assertSame('/x/programy', $twig->getEnvironment()->getGlobals()['screenPath']);
    }

    /**
     * Only the pass-through: Session and Authenticator are final, and refreshCookie() is a
     * no-op under CLI. That a full logged-in page slides the cookie and a fragment does not
     * is SessionRotationTest's, against a real server.
     */
    public function testTheSessionCookieMiddlewareReturnsALoggedOutReadersResponseUnchanged(): void
    {
        $_SESSION = [];
        $session = new Session();
        $clock = new FixedClock(new \DateTimeImmutable('2027-05-21T09:00:00+02:00'));
        $expected = new ResponseFactory()->createResponse();

        $response = new SessionCookieMiddleware(new Authenticator($session, new PairingState($session, $clock)), $session)
            ->process($this->request('/'), $this->handler($expected));

        self::assertSame($expected, $response);
    }

    private function request(string $path): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', $path);
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new readonly class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
