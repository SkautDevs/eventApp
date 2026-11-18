<?php

declare(strict_types=1);

namespace App;

use DI\ContainerBuilder;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

final class Kernel
{
    /** The container key of the error handler's logger: stdout only, never Sentry. */
    public const string ERRORS_LOGGER = 'logger.errors';

    /**
     * The front door. The first path segment names the event; anything else — `/`,
     * an unknown slug — is the instance app. The only place `.env` is read.
     *
     * @return App<ContainerInterface|null>
     */
    public static function boot(string $uri, ?string $eventsDir = null): App
    {
        $root = dirname(__DIR__);
        if (is_file($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }
        // right after .env and before the event is picked, so the instance app is covered too
        Telemetry\Telemetry::init(Environment::fromGlobals());
        $catalog = new EventCatalog($eventsDir ?? $root . '/events');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $first = explode('/', ltrim($path, '/'), 2)[0];

        return $catalog->has($first)
            ? self::create($catalog->load($first))
            : self::createInstance($catalog, Time\Zone::midnight((new Time\SystemClock())->now()));
    }

    /**
     * Error pages: details only under APP_DEBUG, every real error filed with Sentry and
     * logged, a 404 or 405 not. HTML errors are rendered by $template (TwigErrorRenderer);
     * a client that asks for JSON or XML keeps Slim's own renderer.
     *
     * @param App<ContainerInterface|null> $app
     */
    private static function addErrorHandling(App $app, ContainerInterface $container, string $template, bool $debug): void
    {
        $middleware = $app->addErrorMiddleware(displayErrorDetails: $debug, logErrors: true, logErrorDetails: true);
        $logger = $container->get(self::ERRORS_LOGGER);
        // Definitions defines it as a logger in both containers (errorsLogger())
        \assert($logger instanceof LoggerInterface);
        $handler = new QuietErrorHandler($app->getCallableResolver(), $app->getResponseFactory(), $logger);
        $twig = $container->get(Twig::class);
        // Definitions defines it as Twig in both containers
        \assert($twig instanceof Twig);
        $renderer = new Http\TwigErrorRenderer($twig, $template);
        $handler->registerErrorRenderer('text/html', $renderer);
        // Accept: */* (and a fragment request) picks the default, which is the page too
        $handler->setDefaultErrorRenderer('text/html', $renderer);
        $middleware->setDefaultErrorHandler($handler);
        // A route middleware that must put its headers on the error page too (the admin
        // pages' no-store) renders the exception through this itself; see AdminHeadersMiddleware.
        if ($container instanceof \DI\Container) {
            $container->set(\Slim\Middleware\ErrorMiddleware::class, $middleware);
        }
    }

    /**
     * The app for everything that is not an event: the picker at `/`, Slim's own 404
     * for the rest. It knows no event, so its container holds nothing event-shaped.
     *
     * @param array<string, mixed> $containerOverrides
     * @return App<ContainerInterface|null>
     */
    public static function createInstance(EventCatalog $catalog, \DateTimeImmutable $today, array $containerOverrides = []): App
    {
        $root = dirname(__DIR__);
        date_default_timezone_set(Time\Zone::PRAGUE);
        $builder = new ContainerBuilder();
        $builder->addDefinitions(Container\Definitions::forInstance(
            $catalog,
            // taken here rather than in boot(), so a test that sets a variable and then builds
            // an app is read as it set it; every reader gets it from the container
            Environment::fromGlobals(),
            $root,
        ));
        $builder->addDefinitions($containerOverrides);
        $container = $builder->build();
        $environment = $container->get(Environment::class);
        \assert($environment instanceof Environment);

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        // before the routing middleware means inside it, as in create(): the picker is
        // "GET /", and only an unmatched path keeps the name "unmatched"
        $app->add(new Telemetry\RouteNameMiddleware(null));
        $app->addRoutingMiddleware();
        self::addErrorHandling($app, $container, 'instance-error.twig', $environment->debug());
        // the container's, so an override reaches the picker too
        $twig = $container->get(Twig::class);
        \assert($twig instanceof Twig);
        $app->add(new Http\SecurityHeadersMiddleware());
        // after the security headers, so it too is outside the error middleware
        $app->add(new Http\CspMiddleware($twig));
        // Last of all, so it is outermost: the error middleware's 500 is measured too.
        // Keep it the final $app->add() — anything added after it would go unmeasured.
        $app->add(new Telemetry\TransactionMiddleware());

        $events = $container->get(EventCatalog::class);
        \assert($events instanceof EventCatalog);
        $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($today, $twig, $events): ResponseInterface {
            return $twig->render($response, 'picker.twig', $events->listed($today));
        })->setName('picker');

        // What the reverse proxy or an uptime probe polls. It opens the push database and
        // asks it one question; it migrates nothing, logs nothing and reports nothing (the
        // transaction middleware skips the path and any failure is answered right here).
        $dbPath = $environment->pushDbPath($root);
        $app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response) use ($dbPath): ResponseInterface {
            try {
                $statement = Storage\Database::open($dbPath)->query('SELECT 1');
                // the connection throws on a failed query (Database::open() sets ERRMODE_EXCEPTION)
                \assert($statement instanceof \PDOStatement);
                $statement->fetchColumn();
                $status = 200;
                $body = ['ok' => true];
            } catch (\Throwable) {
                $status = 503;
                $body = ['ok' => false];
            }
            $response->getBody()->write((string) json_encode($body));

            return $response
                ->withStatus($status)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        })->setName('health');

        return $app;
    }

    /**
     * @param array<string, mixed> $containerOverrides
     * @return App<ContainerInterface|null>
     */
    public static function create(EventConfig $event, array $containerOverrides = []): App
    {
        $root = dirname(__DIR__);
        // Every date the app formats — the timeline's "today", the kissj datetimes, a
        // subscription's created_at — is a local one. Left unset, PHP defaults to UTC and
        // the whole event runs one or two hours off.
        date_default_timezone_set(Time\Zone::PRAGUE);
        // the configuration this app reads (see createInstance() for why it is taken here),
        // or the one a container override supplies
        $override = $containerOverrides[Environment::class] ?? null;
        $environment = $override instanceof Environment ? $override : Environment::fromGlobals();
        // checked here, before the container exists: a misconfigured event does not boot,
        // rather than failing on its first programme read
        $environment->programSource($event->slug);

        $builder = new ContainerBuilder();
        $builder->addDefinitions(Container\Definitions::forEvent($event, $environment, $root));
        $builder->addDefinitions($containerOverrides);
        $container = $builder->build();
        $environment = $container->get(Environment::class);
        \assert($environment instanceof Environment);

        // A misconfigured push instance fails on the first request to the event, not on the
        // first tap of the button. Building the sender opens no database (its repository is
        // resolved on the first send), so this costs nothing per request. A container
        // override — every test app's fake sender — is simply what gets built.
        if (in_array('push', $event->features, true)) {
            $container->get(\App\Push\PushSenderInterface::class);
        }

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        // Every event lives under its own prefix. Slim prepends the base path to every
        // route pattern and to every url_for(), so modules and templates stay unaware of it.
        $base = '/' . $event->slug;
        $app->setBasePath($base);
        // '/obrok19' is what people type; the homepage route is '/obrok19/'
        $app->redirect('', $base . '/', 301);
        $app->addBodyParsingMiddleware();
        // before the routing middleware means inside it: it runs once the route is known
        $app->add(new Telemetry\RouteNameMiddleware($event->slug));
        $app->addRoutingMiddleware();
        $app->add(TwigMiddleware::createFromContainer($app, Twig::class));
        self::addErrorHandling($app, $container, 'error.twig', $environment->debug());
        $auth = $container->get(Auth\Authenticator::class);
        \assert($auth instanceof Auth\Authenticator);
        $session = $container->get(Session::class);
        \assert($session instanceof Session);
        $app->add(new Http\SessionCookieMiddleware($auth, $session));
        // the container's clock, for the screen middleware; each request reads it once
        $clock = $container->get(ClockInterface::class);
        \assert($clock instanceof ClockInterface);
        $twig = $container->get(Twig::class);
        \assert($twig instanceof Twig);
        $freshness = $container->get(Program\Freshness::class);
        \assert($freshness instanceof Program\Freshness);
        // Added after the error middleware, so it sits outside it rather than inside it;
        // the security headers, the CSP and the transaction middleware wrap it in turn.
        // A thrown 404 or 500 never reaches the route, so a header applied further in
        // would be missing from exactly the responses a shared cache is most likely to keep.
        $app->add(new Http\ScreenMiddleware($twig, $freshness, $clock));
        // Outside the error middleware too, so the error responses get these headers.
        $app->add(new Http\SecurityHeadersMiddleware());
        // after the security headers, so it too is outside the error middleware and a 404
        // or a 500 carries the policy
        $app->add(new Http\CspMiddleware($twig, Http\CspMiddleware::extrasFor($event)));
        // Last of all, so it is outermost: the error middleware's 500 is measured too.
        // Keep it the final $app->add() — anything added after it would go unmeasured.
        $app->add(new Telemetry\TransactionMiddleware());

        // the event's modules, the core ones included; none is built until all() is first
        // called, and an action only when its route runs
        $modules = $container->get(Module\Modules::class);
        \assert($modules instanceof Module\Modules);
        self::registerModules($app, $twig, $modules);

        return $app;
    }

    /**
     * Every module of the event registers its routes, in ModuleRegistry::ORDER, and the tab
     * bar is their menu items in their own order.
     *
     * @param App<ContainerInterface|null> $app
     */
    private static function registerModules(App $app, Twig $twig, Module\Modules $modules): void
    {
        foreach ($modules->all() as $module) {
            $module->registerRoutes($app);
        }
        $twig->getEnvironment()->addGlobal('menu', $modules->menu());
    }
}
