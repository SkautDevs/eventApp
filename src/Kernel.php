<?php

declare(strict_types=1);

namespace App;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Twig\TwigFilter;

final class Kernel
{
    /** Every date the app formats or compares is a local one. */
    private const TIMEZONE = 'Europe/Prague';

    /** The container key of the error handler's logger: stdout only, never Sentry. */
    public const ERRORS_LOGGER = 'logger.errors';

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=()',
    ];

    /**
     * The front door. The first path segment names the event; anything else — `/`,
     * an unknown slug — is the instance app. The only place `.env` is read.
     */
    public static function boot(string $uri, ?string $eventsDir = null): App
    {
        $root = dirname(__DIR__);
        if (is_file($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }
        // right after .env and before the event is picked, so the instance app is covered too
        Telemetry\Telemetry::init();
        $catalog = new EventCatalog($eventsDir ?? $root . '/events');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $first = explode('/', ltrim($path, '/'), 2)[0];

        return $catalog->has($first)
            ? self::create($catalog->load($first))
            : self::createInstance($catalog, self::today());
    }

    /** Midnight of the current day in the app's zone, whatever zone PHP is still in. */
    public static function today(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        return ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
    }

    /** One SQLite file for every event: subscriptions and the message log. */
    private static function pushDbPath(string $root): string
    {
        $path = $_ENV['PUSH_DB_PATH'] ?? 'var/push.sqlite';

        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }

    /**
     * PROGRAM_CACHE_TTL: seconds a kissj answer is served without asking again. Whole
     * seconds only; anything else — empty, negative, "5m" — is the default, 300. 0 asks
     * every time and still keeps the last answer for an outage.
     */
    public static function programCacheTtl(): int
    {
        $value = $_ENV['PROGRAM_CACHE_TTL'] ?? '';

        return is_string($value) && ctype_digit($value) ? (int) $value : 300;
    }

    /**
     * An explicit whitelist rather than a cast: (bool) "false" and (bool) "off" are both
     * true, so an operator writing the obvious APP_DEBUG=false would turn debug pages on.
     */
    public static function debug(): bool
    {
        return in_array($_ENV['APP_DEBUG'] ?? '', ['1', 'true', 'on'], true);
    }

    /**
     * Error pages: details only under APP_DEBUG, every real error filed with Sentry and
     * logged, a 404 or 405 not. HTML errors are rendered by $template (TwigErrorRenderer);
     * a client that asks for JSON or XML keeps Slim's own renderer.
     */
    private static function addErrorHandling(App $app, ContainerInterface $container, string $template): void
    {
        $debug = self::debug();
        $middleware = $app->addErrorMiddleware(displayErrorDetails: $debug, logErrors: true, logErrorDetails: true);
        $handler = new QuietErrorHandler(
            $app->getCallableResolver(),
            $app->getResponseFactory(),
            $container->get(self::ERRORS_LOGGER),
        );
        $renderer = new Http\TwigErrorRenderer($container->get(Twig::class), $template);
        $handler->registerErrorRenderer('text/html', $renderer);
        // Accept: */* (and a fragment request) picks the default, which is the page too
        $handler->setDefaultErrorRenderer('text/html', $renderer);
        $middleware->setDefaultErrorHandler($handler);
    }

    /** The error handler's own channel: the exception already went to Sentry through the Collector. */
    private static function errorsLogger(): \Psr\Log\LoggerInterface
    {
        return new \Monolog\Logger('errors', [new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Info)]);
    }

    /**
     * The app for everything that is not an event: the picker at `/`, Slim's own 404
     * for the rest. It knows no event, so its container holds nothing event-shaped.
     */
    public static function createInstance(EventCatalog $catalog, \DateTimeImmutable $today, array $containerOverrides = []): App
    {
        $root = dirname(__DIR__);
        date_default_timezone_set(self::TIMEZONE);

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            EventCatalog::class => $catalog,
            Twig::class => function () use ($root): Twig {
                $twig = Twig::create($root . '/templates', ['cache' => false]);
                // registered so CspMiddleware may overwrite it per request
                $twig->getEnvironment()->addGlobal('cspNonce', '');

                return $twig;
            },
            self::ERRORS_LOGGER => fn (): \Psr\Log\LoggerInterface => self::errorsLogger(),
        ]);
        $builder->addDefinitions($containerOverrides);
        $container = $builder->build();

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        // before the routing middleware means inside it, as in create(): the picker is
        // "GET /", and only an unmatched path keeps the name "unmatched"
        $app->add(new Telemetry\RouteNameMiddleware(null));
        $app->addRoutingMiddleware();
        self::addErrorHandling($app, $container, 'instance-error.twig');
        self::addSecurityHeadersMiddleware($app);
        // after the security headers, so it too is outside the error middleware
        $app->add(new Http\CspMiddleware($container->get(Twig::class)));
        // Last of all, so it is outermost: the error middleware's 500 is measured too.
        // Keep it the final $app->add() — anything added after it would go unmeasured.
        $app->add(new Telemetry\TransactionMiddleware());

        $app->get('/', function ($request, $response) use ($today) {
            return $this->get(Twig::class)->render($response, 'picker.twig', $this->get(EventCatalog::class)->listed($today));
        })->setName('picker');

        // What the reverse proxy or an uptime probe polls. It opens the push database and
        // asks it one question; it migrates nothing, logs nothing and reports nothing (the
        // transaction middleware skips the path and any failure is answered right here).
        $dbPath = self::pushDbPath($root);
        $app->get('/health', function ($request, $response) use ($dbPath) {
            try {
                Storage\Database::open($dbPath)->query('SELECT 1')->fetchColumn();
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

    public static function create(EventConfig $event, array $containerOverrides = []): App
    {
        $root = dirname(__DIR__);
        // Every date the app formats — the timeline's "today", the kissj datetimes, a
        // subscription's created_at — is a local one. Left unset, PHP defaults to UTC and
        // the whole event runs one or two hours off.
        date_default_timezone_set(self::TIMEZONE);

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            EventConfig::class => $event,
            \App\Program\ProgramProviderInterface::class => function (ContainerInterface $c) use ($event, $root): \App\Program\ProgramProviderInterface {
                if ($event->env('PROGRAM_PROVIDER', 'stub') === 'kissj') {
                    $baseUrl = $_ENV['KISSJ_BASE_URL'] ?? '';
                    if ($baseUrl === '') {
                        throw new \RuntimeException('PROGRAM_PROVIDER=kissj requires KISSJ_BASE_URL');
                    }
                    // kissj resolves the event from the key, so the key is what picks the event
                    $apiKey = $event->env('KISSJ_API_KEY');
                    if ($apiKey === '') {
                        throw new \RuntimeException(sprintf('PROGRAM_PROVIDER=kissj requires %s', $event->envKey('KISSJ_API_KEY')));
                    }

                    // Only kissj is cached: the stub's fixture files already are a local copy.
                    // One timeout for every endpoint — a shorter one on the participant call
                    // would reject a slow-but-working kissj on the one call (login) that has no
                    // entry to fall back to, and with the cache a slow kissj is paid once per TTL.
                    return new Program\CachingProgramProvider(
                        inner: new \App\Program\KissjProgramProvider(
                            http: new \GuzzleHttp\Client(['base_uri' => rtrim($baseUrl, '/') . '/', 'timeout' => 10]),
                            apiKey: $apiKey,
                        ),
                        cache: new Cache\FileCache($root . '/var/cache/' . $event->slug),
                        freshness: $c->get(Program\Freshness::class),
                        ttl: self::programCacheTtl(),
                    );
                }

                return new \App\Program\StubProgramProvider($event->dir . '/fixtures');
            },
            // how old this request's provider data is; the screen middleware resets it per request
            Program\Freshness::class => fn (): Program\Freshness => new Program\Freshness(),
            // content hashes for the stylesheet and the scripts: asset_version() in the
            // layout, and the service worker's precache list (Task 5)
            Http\AssetVersion::class => fn (): Http\AssetVersion => new Http\AssetVersion($root . '/www'),
            Session::class => fn (): Session => new Session($event->slug),
            Auth\Authenticator::class => \DI\autowire(),
            Auth\LoginThrottle::class => \DI\autowire(),
            // One connection per request, opened and migrated on first use only: PHP-DI
            // builds a definition on the first get(), so the homepage, the map, the links
            // and the handbook never touch the file. A request that does pays one
            // `PRAGMA user_version` read; it stays per request because the Apache path
            // has no deploy step to hook a migration into.
            \PDO::class => function () use ($root): \PDO {
                $pdo = Storage\Database::open(self::pushDbPath($root));
                (new Storage\Migrator())->migrate($pdo);

                return $pdo;
            },
            \App\Push\SubscriptionRepository::class => \DI\autowire(),
            \App\Push\MessageRepository::class => \DI\autowire(),
            \App\Push\EndpointPolicy::class => fn (): \App\Push\EndpointPolicy => \App\Push\EndpointPolicy::fromEnvironment(),
            // Neither logger has a WebProcessor: request data reaches Sentry through the
            // SDK's own request integration, where the Scrubber sees it. Sentry gets an
            // issue only from warning up: the routine push.sent line is a log record, and
            // the counts it carries live on the push.send span; push.failed is a warning.
            \Psr\Log\LoggerInterface::class => fn (): \Psr\Log\LoggerInterface => new \Monolog\Logger('eventapp', [
                new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Info),
                new \Sentry\Monolog\LogToSentryIssueHandler(\Sentry\SentrySdk::getCurrentHub(), \Monolog\Level::Warning),
            ]),
            self::ERRORS_LOGGER => fn (): \Psr\Log\LoggerInterface => self::errorsLogger(),
            \App\Push\PushSenderInterface::class => fn (\Psr\Container\ContainerInterface $c): \App\Push\PushSenderInterface => new \App\Push\WebPushSender(
                // resolved on the first send: building the sender must not open the database
                repository: static fn (): \App\Push\SubscriptionRepository => $c->get(\App\Push\SubscriptionRepository::class),
                endpoints: $c->get(\App\Push\EndpointPolicy::class),
                vapidPublicKey: $_ENV['VAPID_PUBLIC_KEY'] ?? '',
                vapidPrivateKey: $_ENV['VAPID_PRIVATE_KEY'] ?? '',
                vapidSubject: $_ENV['VAPID_SUBJECT'] ?? '',
                logger: $c->get(\Psr\Log\LoggerInterface::class),
            ),
            Twig::class => function (ContainerInterface $c) use ($root, $event): Twig {
                // Without a cache Twig lexes, parses, compiles and eval()s the layout and
                // every template from source on *every* request — two thirds of the time a
                // page takes. The compiled output reads globals rather than event literals,
                // so one directory is shared by every event. auto_reload is always on: Twig's
                // cache key is the template name, not its source, so without the mtime check
                // an edited template keeps being served from the old compiled copy (tests
                // and a redeploy alike). One stat per template is negligible.
                $cacheDir = $root . '/var/twig';
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0o775, true);
                }
                $twig = Twig::create($root . '/templates', [
                    'cache' => is_dir($cacheDir) && is_writable($cacheDir) ? $cacheDir : false,
                    'auto_reload' => true,
                ]);
                $env = $twig->getEnvironment();
                // 'theme' and 'roles' are spread in explicitly so they are arrays even for
                // an event whose config never mentions them — the layout iterates them unguarded
                $env->addGlobal('event', $event->raw + [
                    'slug' => $event->slug,
                    'theme' => $event->theme,
                    'roles' => $event->roles,
                ]);
                $env->addGlobal('base', '/' . $event->slug);
                $env->addGlobal('vapidPublicKey', $_ENV['VAPID_PUBLIC_KEY'] ?? '');
                // Registered here so the per-request middleware below can *update* them:
                // Twig refuses to add a global once the environment is initialised, but
                // it is happy to overwrite one that already exists. screenPath is the
                // path the screen was served from — not get_uri(), whose runtime slim/
                // twig-view resolves once and then keeps for the life of the app.
                $env->addGlobal('fragment', false);
                $env->addGlobal('screenPath', '/');
                // CspMiddleware writes the request's nonce here; every inline <script> carries it
                $env->addGlobal('cspNonce', '');
                // the app bar shows who is logged in. It has to be a function, not a global:
                // a handler may log the user out (expired TIE code) during the very request
                // whose response then renders the bar.
                $env->addFunction(new \Twig\TwigFunction(
                    'auth_identity',
                    fn (): ?string => $c->get(Auth\Authenticator::class)->identity()?->displayName,
                ));
                $env->addFunction(new \Twig\TwigFunction(
                    'auth_tie_code',
                    fn (): ?string => $c->get(Auth\Authenticator::class)->identity()?->tieCode,
                ));
                // The age of the provider data on this screen. A function rather than a
                // global for the same reason as auth_identity(): the handler fills it while
                // it runs, before the layout renders.
                $env->addFunction(new \Twig\TwigFunction(
                    'data_freshness',
                    fn (): array => $c->get(Program\Freshness::class)->forView(),
                ));
                // `style.css` → `style.css?v=<first 8 hex of its sha256>`: the URL changes
                // exactly when the bytes do, so nothing is bumped by hand
                $env->addFunction(new \Twig\TwigFunction(
                    'asset_version',
                    fn (string $path): string => $c->get(Http\AssetVersion::class)->url($path),
                ));
                $env->addFilter(new TwigFilter('dateToCzechDayName', function (array $datetimeArray): string {
                    $day = (new \DateTime($datetimeArray['date']))->format('D');

                    return [
                        'Mon' => 'pondělí', 'Tue' => 'úterý', 'Wed' => 'středa', 'Thu' => 'čtvrtek',
                        'Fri' => 'pátek', 'Sat' => 'sobota', 'Sun' => 'neděle',
                    ][$day];
                }));

                return $twig;
            },
        ]);
        $builder->addDefinitions($containerOverrides);
        $container = $builder->build();

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
        self::addErrorHandling($app, $container, 'error.twig');
        // Added after the error middleware, so it sits outside it rather than inside it;
        // the security headers, the CSP and the transaction middleware wrap it in turn.
        // A thrown 404 or 500 never reaches the route, so a header applied further in
        // would be missing from exactly the responses a shared cache is most likely to keep.
        self::addScreenMiddleware($app, $container);
        // For the same reason: an error page is exactly the response that must not be
        // sniffed or framed, and it is the one the error middleware answers on its own.
        self::addSecurityHeadersMiddleware($app);
        // after the security headers, so it too is outside the error middleware and a 404
        // or a 500 carries the policy
        $app->add(new Http\CspMiddleware($container->get(Twig::class), Http\CspMiddleware::extrasFor($event)));
        // Last of all, so it is outermost: the error middleware's 500 is measured too.
        // Keep it the final $app->add() — anything added after it would go unmeasured.
        $app->add(new Telemetry\TransactionMiddleware());

        self::registerCoreRoutes($app);
        self::registerModules($app, $container, $event);

        return $app;
    }

    /**
     * Fragment mode. A request carrying `X-Screen: 1` renders the screen without the
     * shell: the templates pick their parent from the `fragment` global, so the same
     * route and the same handler serve both a whole page and a bare screen. A plain
     * request is byte-identical to what it was before this existed, which is what keeps
     * deep links, crawlers and a no-JS reader working.
     *
     * It sits outside the error middleware (the security headers, the CSP and the
     * transaction middleware wrap it in turn), which is what puts `Vary: X-Screen` on an
     * error response too: Slim's error middleware answers a 404 or a 500 without ever calling
     * anything further in, so a header set inside it would be skipped for precisely the
     * responses that differ by the header. The loader treats any non-200 as "not a
     * screen" and falls back to a real navigation, so a full error page is never
     * injected into a <section>.
     */
    private static function addScreenMiddleware(App $app, ContainerInterface $container): void
    {
        $app->add(function ($request, $handler) use ($container) {
            $env = $container->get(Twig::class)->getEnvironment();
            $env->addGlobal('fragment', $request->getHeaderLine('X-Screen') === '1');
            $env->addGlobal('screenPath', $request->getUri()->getPath());
            // Request-scoped: in a long-lived app the container, and this one instance with
            // it, outlives the request, and a stale flag must not leak into the next screen.
            $container->get(Program\Freshness::class)->reset(new \DateTimeImmutable());

            // the two responses differ for the same URL, so anything caching them has to
            // key on the header as well
            return $handler->handle($request)->withHeader('Vary', 'X-Screen');
        });
    }

    /**
     * The response headers that cost nothing and break nothing here: the app serves no
     * user-uploaded file, never frames itself, and asks for none of the powerful
     * features. The Content-Security-Policy is CspMiddleware's, because it needs a
     * per-request nonce; www/.htaccess and docker/nginx.conf repeat these four for static
     * files and deliberately not the CSP, which does nothing on a non-document. Still no
     * HSTS — that needs TLS to exist first, see docs/deployment.md.
     */
    private static function addSecurityHeadersMiddleware(App $app): void
    {
        // deliberately not a static closure: Slim binds every middleware Closure to the
        // container, and a static one cannot be bound at all
        $app->add(function ($request, $handler) {
            $response = $handler->handle($request);
            foreach (self::SECURITY_HEADERS as $name => $value) {
                // a route that needs a stricter value (the admin pages' no-referrer) keeps its own
                if (!$response->hasHeader($name)) {
                    $response = $response->withHeader($name, $value);
                }
            }

            return $response;
        });
    }

    private static function registerCoreRoutes(App $app): void
    {
        $base = $app->getBasePath();

        $app->get('/', function ($request, $response) {
            return $this->get(Twig::class)->render($response, 'homepage.twig', [
                'links' => $this->get(EventConfig::class)->content('links'),
            ]);
        })->setName('homepage');

        $app->get('/profil', function ($request, $response) {
            $auth = $this->get(Auth\Authenticator::class);
            $session = $this->get(Session::class);
            $tieError = $session->get('tieError');
            $session->delete('tieError');

            return $this->get(Twig::class)->render($response, 'profile.twig', [
                'isLogged' => $auth->isLogged(),
                'identity' => $auth->identity()?->displayName,
                'tieError' => $tieError,
            ]);
        })->setName('profile');

        $app->post('/profil/tie', function ($request, $response) use ($base) {
            $body = (array) $request->getParsedBody();
            $code = strtoupper(trim((string) ($body['tieCode'] ?? '')));
            // Můj program carries its own form and wants the reader back on the list;
            // anything else (or a forged value) goes to /profil as before
            $target = ($body['return'] ?? null) === 'programy' ? '/programy#muj-program' : '/profil';
            $session = $this->get(Session::class);

            // the outcome is a tag on the transaction; the code itself never leaves this closure
            Telemetry\Tracer::span('auth.tie', 'POST /profil/tie', function () use ($request, $code, $session): void {
                if ($code === '') {
                    Telemetry\Tracer::tag('tie_outcome', 'empty');

                    return;
                }
                $slug = $this->get(EventConfig::class)->slug;
                $ip = Http\ClientIp::of($request);
                $throttle = $this->get(Auth\LoginThrottle::class);
                if ($throttle->tooMany($slug, $ip)) {
                    // checked before the provider: against kissj every guess is an upstream request
                    $session->set('tieError', 'Příliš mnoho pokusů, zkus to za chvíli.');
                    Telemetry\Tracer::tag('tie_outcome', 'rate_limited');

                    return;
                }
                $identity = new Auth\Identity(displayName: 'TIE ' . $code, tieCode: $code);
                try {
                    $this->get(\App\Program\ProgramProviderInterface::class)->getProgramsForIdentity($identity);
                    $this->get(Auth\Authenticator::class)->store($identity);
                    $session->delete('tieError');
                    Telemetry\Tracer::tag('tie_outcome', 'ok');
                } catch (Auth\UnknownParticipantException) {
                    // the only outcome that counts: the limit is against guessing
                    $throttle->recordFailure($slug, $ip);
                    $session->set('tieError', 'Neplatný TIE kód.');
                    Telemetry\Tracer::tag('tie_outcome', 'unknown');
                } catch (\GuzzleHttp\Exception\TransferException|\App\Program\ProgramDataException) {
                    // never arrived, or arrived but wrong: one notice for both
                    $session->set('tieError', 'Přihlášení se teď nedaří, zkus to prosím později.');
                    Telemetry\Tracer::tag('tie_outcome', 'provider_failed');
                }
            });

            return $response->withHeader('Location', $base . $target)->withStatus(302);
        })->setName('tie-login');

        $app->post('/profil/tie-logout', function ($request, $response) use ($base) {
            $this->get(Auth\Authenticator::class)->logout();

            return $response->withHeader('Location', $base . '/profil')->withStatus(302);
        })->setName('tie-logout');

        // The worker's last fallback for a page that is neither cached nor reachable. It is
        // in the precache list, so every installed worker holds it.
        $app->get('/offline', function ($request, $response) {
            return $this->get(Twig::class)->render($response, 'offline.twig');
        })->setName('offline');

        // What the service worker keeps (www/sw.js install): the screens, the content-hashed
        // assets, the optional handbook. no-cache: the worker must see a new list the moment
        // an asset changes. A path with a dot: bin/router.php gets it past `php -S`.
        $app->get('/precache.json', function ($request, $response) use ($app) {
            $event = $this->get(EventConfig::class);
            $body = Http\Precache::build(
                $event,
                $app->getRouteCollector()->getRouteParser(),
                $this->get(Http\AssetVersion::class),
                array_column(Kernel::menu($this, $event), 'route'),
                dirname(__DIR__) . '/www',
            );
            $response->getBody()->write((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-cache');
        })->setName('precache');
    }

    private static function registerModules(App $app, ContainerInterface $container, EventConfig $event): void
    {
        foreach ($event->features as $feature) {
            $container->get(Module\ModuleRegistry::classFor($feature))->registerRoutes($app);
        }
        $container->get(Twig::class)->getEnvironment()->addGlobal('menu', self::menu($container, $event));
    }

    /**
     * The tab bar: every enabled module's menu item, in its own order rather than the
     * order the features happen to be listed in. The precache list reads it too.
     *
     * @return list<array{label: string, route: string, icon: string, order: int, key: string}>
     */
    public static function menu(ContainerInterface $container, EventConfig $event): array
    {
        $menu = [];
        foreach ($event->features as $feature) {
            if (($item = $container->get(Module\ModuleRegistry::classFor($feature))->menuItem()) !== null) {
                $menu[] = $item + ['key' => $feature];
            }
        }
        usort($menu, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $menu;
    }
}
