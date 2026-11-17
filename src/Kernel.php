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
    private const string TIMEZONE = 'Europe/Prague';

    /** The container key of the error handler's logger: stdout only, never Sentry. */
    public const string ERRORS_LOGGER = 'logger.errors';

    /** Every HTML page and fragment without a policy of its own (addScreenMiddleware()). */
    public const string PAGE_CACHE_CONTROL = 'no-store, no-cache, must-revalidate';

    /**
     * The line a logged-out Profil shows after a pairing ended without a login, or a start
     * or a session went wrong, by the code in the session's pairingOutcome.
     */
    private const array PAIRING_OUTCOMES = [
        'expired' => 'Kód vypršel, začni znovu.',
        'gone' => 'Propojení s KissJ už neplatí, přihlas se znovu.',
        'busy' => 'Teď se propojuje moc lidí najednou, zkus to za chvíli.',
        'unavailable' => 'Propojení se teď nedaří, zkus to prosím později.',
    ];

    /**
     * How long an outcome line stays on Profil. Reading it consumes nothing — the loader's
     * warm-up and the worker's refill read Profil too, and would swallow it — so it ends
     * with the next start, an approval, or this.
     */
    private const int PAIRING_OUTCOME_SECONDS = 600;

    /** The session key of the one pending server toast (takeToast()). */
    private const string TOAST_KEY = 'toast';

    /**
     * How long a pending toast waits for its page. It is said right after what caused it (a
     * login, a logout); one still in the session a minute later would greet the reader with
     * news about something long past.
     */
    private const int TOAST_SECONDS = 60;

    /**
     * The response header of a page or fragment that carries a toast (markToast()). The worker
     * never stores such a response (www/sw.js), or its offline copy would say the toast again.
     */
    public const string TOAST_HEADER = 'X-Toast';

    /**
     * The request header of the worker's own fetches of a whole document, to fill or refill
     * the offline copy (www/sw.js storeBoth()). Such a request is not the reader looking, so
     * it takes no toast (takeToast()).
     */
    public const string WORKER_FILL_HEADER = 'X-Worker-Fill';

    /** The toast on the first full Profil after a pairing was approved. */
    private const array LOGIN_TOAST = ['title' => 'Jsi přihlášen', 'line' => null];

    /** The toast on the first full Profil after a logout. */
    private const array LOGOUT_TOAST = ['title' => 'Odhlášeno', 'line' => 'Obsah teď můžeš jen prohlížet'];

    private const array SECURITY_HEADERS = [
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
     * SESSION_PATH: the session store's directory, var/sessions unless set; relative paths
     * are resolved against the project root like PUSH_DB_PATH. The browser tests point it
     * at a temporary directory of their own.
     */
    public static function sessionPath(string $root): string
    {
        $path = $_ENV['SESSION_PATH'] ?? '';
        if ($path === '') {
            return $root . '/var/sessions';
        }

        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }

    /**
     * STUB_STATE_PATH: where the `local` programme source keeps the registrations made in
     * the app and the revoked sessions, var/stub unless set; relative paths are resolved
     * against the project root like SESSION_PATH. The tests point it at temporary
     * directories of their own.
     */
    public static function stubStatePath(string $root): string
    {
        $path = $_ENV['STUB_STATE_PATH'] ?? '';
        if (!is_string($path) || $path === '') {
            return $root . '/var/stub';
        }

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
     * PROGRAM_PROVIDER_<SLUG>, required for every event: `local` (the event's fixture
     * files; null here) or the http(s) root URL of a kissj instance, returned without a
     * trailing slash. Anything else — a missing value included — stops the event from
     * booting: no event shows demo data by default. A URL also needs KISSJ_API_KEY_<SLUG>,
     * because kissj resolves the event from the key. The messages name the variables,
     * never their values.
     */
    public static function programSource(EventConfig $event): ?string
    {
        $value = $event->env('PROGRAM_PROVIDER');
        if ($value === 'local') {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (!in_array(is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new \RuntimeException(sprintf(
                '%s must be "local" or the http(s) root URL of a kissj instance',
                $event->envKey('PROGRAM_PROVIDER'),
            ));
        }
        if ($event->env('KISSJ_API_KEY') === '') {
            throw new \RuntimeException(sprintf(
                '%s is a kissj URL and requires %s',
                $event->envKey('PROGRAM_PROVIDER'),
                $event->envKey('KISSJ_API_KEY'),
            ));
        }

        return rtrim($value, '/');
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
        // A route middleware that must put its headers on the error page too (the admin
        // pages' no-store) renders the exception through this itself; see PushModule.
        if ($container instanceof \DI\Container) {
            $container->set(\Slim\Middleware\ErrorMiddleware::class, $middleware);
        }
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
        // checked here, before the container exists: a misconfigured event does not boot,
        // rather than failing on its first programme read
        $programSource = self::programSource($event);

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            EventConfig::class => $event,
            \App\Program\ProgramProviderInterface::class => function (ContainerInterface $c) use ($event, $root, $programSource): \App\Program\ProgramProviderInterface {
                if ($programSource !== null) {
                    // kissj resolves the event from the key, so the key is what picks the event
                    $apiKey = $event->env('KISSJ_API_KEY');

                    // Only kissj is cached: the stub's fixture files already are a local copy.
                    // One timeout for every endpoint — a shorter one on the participant call
                    // would reject a slow-but-working kissj on the one call (login) that has no
                    // entry to fall back to, and with the cache a slow kissj is paid once per TTL.
                    return new Program\CachingProgramProvider(
                        inner: new \App\Program\KissjProgramProvider(
                            http: new \GuzzleHttp\Client(['base_uri' => $programSource . '/', 'timeout' => 10]),
                            apiKey: $apiKey,
                        ),
                        cache: new Cache\FileCache($root . '/var/cache/' . $event->slug),
                        freshness: $c->get(Program\Freshness::class),
                        ttl: self::programCacheTtl(),
                    );
                }

                return new \App\Program\StubProgramProvider($event->dir . '/fixtures', self::stubStatePath($root));
            },
            // how old this request's provider data is; the screen middleware resets it per request
            Program\Freshness::class => fn (): Program\Freshness => new Program\Freshness(),
            // content hashes for the stylesheet and the scripts: asset_version() in the
            // layout, and the service worker's precache list (Task 5)
            Http\AssetVersion::class => fn (): Http\AssetVersion => new Http\AssetVersion($root . '/www'),
            // var/sessions (or SESSION_PATH), created 0700 on first use: nothing about sessions
            // depends on the host's ini (the FTP host's handler and defaults are unknown)
            Session::class => fn (): Session => new Session($event->slug, self::sessionPath($root), appRoot: $root),
            Auth\Authenticator::class => \DI\autowire(),
            Auth\PairingThrottle::class => \DI\autowire(),
            \App\Push\SubscribeThrottle::class => \DI\autowire(),
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
                // the app bar shows who is paired. It has to be a function, not a global: a
                // handler may log the reader out (kissj no longer knows the session) during
                // the very request whose response then renders the bar. The session token
                // never reaches a template.
                $env->addFunction(new \Twig\TwigFunction(
                    'auth_identity',
                    function () use ($c): ?array {
                        $identity = $c->get(Auth\Authenticator::class)->identity();

                        return $identity === null ? null : [
                            'participantId' => $identity->participantId,
                            'displayName' => $identity->displayName(),
                            'initials' => $identity->initials(),
                        ];
                    },
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
                    return Home\HomeModel::WEEKDAYS[(int) (new \DateTime($datetimeArray['date']))->format('N')];
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
        self::addSessionCookieMiddleware($app, $container);
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
     * The login cookie slides: every full page a logged-in reader gets re-issues it with a
     * fresh 30-day expiry (same ID). A fragment, a redirect, JSON or an error page does not.
     * Outside the error middleware like the screen middleware, so it sees the final response.
     */
    private static function addSessionCookieMiddleware(App $app, ContainerInterface $container): void
    {
        $app->add(function ($request, $handler) use ($container) {
            $response = $handler->handle($request);
            $type = $response->getHeaderLine('Content-Type');
            if ($request->getMethod() === 'GET'
                && $request->getHeaderLine('X-Screen') !== '1'
                && $response->getStatusCode() === 200
                && ($type === '' || str_starts_with($type, 'text/html'))
                && $container->get(Auth\Authenticator::class)->isLogged()
            ) {
                $container->get(Session::class)->refreshCookie();
            }

            return $response;
        });
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
            $response = $handler->handle($request)->withHeader('Vary', 'X-Screen');
            // A page carries the identity (the app bar, the registered marking), so no shared
            // or HTTP cache keeps it: stated here for every page and fragment, cookie or not,
            // because Session leaves PHP's cache limiter off. A route with its own policy
            // (the admin pages' no-store) keeps it. The worker's Cache API copy is unaffected.
            // An untyped response counts as a page, so a redirect or a 204 gets the header
            // too — harmless, neither has a body worth caching.
            $type = $response->getHeaderLine('Content-Type');
            if (!$response->hasHeader('Cache-Control') && ($type === '' || str_starts_with($type, 'text/html'))) {
                $response = $response->withHeader('Cache-Control', self::PAGE_CACHE_CONTROL);
            }

            return $response;
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
            $event = $this->get(EventConfig::class);
            $now = new \DateTimeImmutable();
            $phase = Home\HomeModel::phase($event, $now);
            $programs = in_array('programs', $event->features, true);
            $provider = $programs ? $this->get(Program\ProgramProviderInterface::class) : null;
            $auth = $this->get(Auth\Authenticator::class);
            $identity = $auth->identity();

            // Every provider read is best effort: whatever the provider says, the homepage
            // renders. A session kissj no longer knows logs the reader out (Profil says
            // why); an outage logs nobody out and only leaves its part of the screen out.
            // Before the start the screen shows no programme of the day, so nothing
            // personal is read.
            $mine = [];
            $toast = null;
            if ($provider !== null && $identity !== null && $phase !== 'before') {
                try {
                    $mine = $provider->getParticipant($identity->sessionToken)->programmes;
                } catch (Program\SessionExpiredException) {
                    Kernel::sessionGone($auth, $this->get(Session::class));
                    $identity = null;
                    // this very page renders logged out, so it says why itself
                    $toast = Kernel::goneToast();
                } catch (\App\Program\ProgramUnavailableException | Program\ProgramDataException) {
                    $mine = [];
                }
            }
            $sections = [];
            if ($provider !== null && $mine !== []) {
                try {
                    $sections = $provider->getSections();
                } catch (\App\Program\ProgramUnavailableException | Program\ProgramDataException) {
                    // the cards lose their third line, nothing else
                }
            }
            $window = new Program\RegistrationWindow(null, null);
            if ($provider !== null && $phase === 'before') {
                try {
                    $window = $provider->getRegistrationWindow();
                } catch (\App\Program\ProgramUnavailableException | Program\ProgramDataException) {
                    // no window card
                }
            }
            // today's notifications, from the start: the ones this reader may see
            $news = [];
            if ($phase !== 'before' && in_array('info', $event->features, true)) {
                $names = [];
                foreach ($mine as $programme) {
                    $names[(int) $programme['id']] = (string) ($programme['name'] ?? '');
                }
                $news = Push\NewsItems::items($this->get(Push\MessageRepository::class)->visible($event->slug), $names, $now);
            }

            return Kernel::markToast($this->get(Twig::class)->render($response, 'homepage.twig', [
                'links' => $event->content('links'),
                'home' => Home\HomeModel::build($event, $news, $mine, $sections, $identity !== null, $window, $now),
                'toast' => $toast,
            ]), $toast);
        })->setName('homepage');

        $app->get('/profil', function ($request, $response) {
            $auth = $this->get(Auth\Authenticator::class);
            $session = $this->get(Session::class);
            $identity = $auth->identity();
            $pairing = $identity === null ? Kernel::sessionPairing($session) : null;
            // a login's or a logout's, once
            $toast = Kernel::takeToast($request, $session);

            return Kernel::markToast($this->get(Twig::class)->render($response, 'profile.twig', [
                'identity' => $identity === null ? null : [
                    'displayName' => $identity->displayName(),
                    'initials' => $identity->initials(),
                ],
                'pairing' => $pairing === null ? null : [
                    'userCode' => $pairing->formattedUserCode(),
                    'verificationUrl' => $pairing->verificationUrl,
                ],
                // the start's, the poll's or a session's outcome, on every read while it lasts
                'outcome' => Kernel::pairingOutcomeLine($session),
                'toast' => $toast,
            ]), $toast);
        })->setName('profile');

        // Starts a pairing for this browser: kissj gives a code the reader enters in KissJ,
        // and Profil polls until it is approved. The device id is the hash of the session ID,
        // so a second start replaces this browser's pending pairing at kissj.
        $app->post('/profil/propojit', function ($request, $response) use ($base) {
            // a cross-site form must not start a pairing the attacker would then approve
            if (!Http\SameOrigin::allows($request)) {
                return self::loginRefused($this->get(Twig::class), $response, $base);
            }
            $session = $this->get(Session::class);
            // already paired: nothing to start, and no attempt to count
            if ($this->get(Auth\Authenticator::class)->isLogged()) {
                return $response->withHeader('Location', $base . '/profil')->withStatus(302);
            }

            Telemetry\Tracer::span('auth.pairing', 'POST /profil/propojit', function () use ($request, $session): void {
                $slug = $this->get(EventConfig::class)->slug;
                $ip = Http\ClientIp::throttleKey($request);
                $throttle = $this->get(Auth\PairingThrottle::class);
                // every start is a pending pairing at kissj, which caps them per event
                if ($throttle->tooMany($slug, $ip)) {
                    $session->transact(static function (array &$bag): void {
                        unset($bag['pairing']);
                        $bag['pairingOutcome'] = ['code' => 'busy', 'at' => time()];
                    });
                    Telemetry\Tracer::tag('pairing_outcome', 'rate_limited');

                    return;
                }
                $throttle->record($slug, $ip);
                try {
                    $pairing = $this->get(Program\ProgramProviderInterface::class)->startPairing(hash('sha256', $session->id()));
                    $session->transact(static function (array &$bag) use ($pairing): void {
                        unset($bag['pairingOutcome']);
                        $bag['pairing'] = [
                            'deviceCode' => $pairing->deviceCode,
                            'userCode' => $pairing->userCode,
                            'verificationUrl' => $pairing->verificationUrl,
                            'expiresAt' => $pairing->expiresAt->format(\DATE_ATOM),
                        ];
                    });
                    Telemetry\Tracer::tag('pairing_outcome', 'started');
                } catch (\App\Program\ProgramUnavailableException | Program\ProgramDataException) {
                    // never arrived, kissj's cap, or an answer that is not a pairing: one notice
                    $session->transact(static function (array &$bag): void {
                        unset($bag['pairing']);
                        $bag['pairingOutcome'] = ['code' => 'unavailable', 'at' => time()];
                    });
                    Telemetry\Tracer::tag('pairing_outcome', 'provider_failed');
                }
            });

            return $response->withHeader('Location', $base . '/profil')->withStatus(302);
        })->setName('pairing-start');

        // What Profil's script asks every 5 s while the code is shown (www/profile.js).
        // It is always a 200 with one of the contract's statuses: "pending" (and "slowDown")
        // mean ask again, "approved" means the reader is now logged in, "expired" ends the
        // pairing and leaves the line to Profil. A failure is "pending" too: the page keeps
        // asking and the pairing stays, so kissj's re-issued approval is collected later.
        $app->get('/profil/propojit/stav', function ($request, $response) {
            $session = $this->get(Session::class);
            $status = Telemetry\Tracer::span('auth.pairing', 'GET /profil/propojit/stav', function () use ($session): string {
                $pairing = Kernel::sessionPairing($session);
                if ($pairing === null) {
                    return Program\PairingStatus::Expired->value;
                }
                $provider = $this->get(Program\ProgramProviderInterface::class);
                try {
                    $poll = $provider->pollPairing($pairing->deviceCode);
                } catch (\App\Program\ProgramUnavailableException | Program\ProgramDataException $e) {
                    Telemetry\Collector::collect($e);

                    return Program\PairingStatus::Pending->value;
                }

                switch ($poll->status) {
                    case Program\PairingStatus::Approved:
                        try {
                            if ($poll->sessionToken === null) {
                                throw new Program\ProgramDataException('An approved pairing without a session token');
                            }
                            $participant = $provider->getParticipant($poll->sessionToken);
                        } catch (\Throwable $e) {
                            // nothing is stored and the pairing stays: the next poll collects
                            // the approval kissj re-issues within a minute
                            Telemetry\Collector::collect($e);

                            return Program\PairingStatus::Pending->value;
                        }
                        // the pairing goes before the ID rotates, so the old ID holds neither it
                        // nor the identity: a late poll under it cannot collect a re-issued
                        // approval, which would end the token just stored. An old outcome
                        // line goes with it: the reader is in now.
                        $session->transact(static function (array &$bag): void {
                            unset($bag['pairing'], $bag['pairingOutcome']);
                        });
                        $this->get(Auth\Authenticator::class)->store(new Auth\Identity($participant->id, $participant->nickname, $poll->sessionToken));
                        // after the rotation: only the new ID carries it to the Profil the page reloads
                        $session->set(Kernel::TOAST_KEY, Kernel::LOGIN_TOAST + ['at' => time()]);
                        Telemetry\Tracer::tag('pairing_outcome', 'approved');

                        return $poll->status->value;
                    case Program\PairingStatus::Expired:
                        $outcome = $poll->status->value;
                        $session->transact(static function (array &$bag) use ($outcome): void {
                            unset($bag['pairing']);
                            $bag['pairingOutcome'] = ['code' => $outcome, 'at' => time()];
                        });
                        Telemetry\Tracer::tag('pairing_outcome', $outcome);

                        return $outcome;
                    default:
                        return $poll->status->value;
                }
            });
            $response->getBody()->write((string) json_encode(['status' => $status]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        })->setName('pairing-poll');

        // The logout: kissj is asked to end the device session (best effort: a reader is
        // logged out of the app whatever kissj says), then the identity is dropped.
        $app->post('/profil/odpojit', function ($request, $response) use ($base) {
            if (!Http\SameOrigin::allows($request)) {
                return self::loginRefused($this->get(Twig::class), $response, $base, 'Odhlášení se nepodařilo. Načti stránku a zkus to znovu.');
            }
            $auth = $this->get(Auth\Authenticator::class);
            $identity = $auth->identity();
            if ($identity !== null) {
                try {
                    $this->get(Program\ProgramProviderInterface::class)->revokeSession($identity->sessionToken);
                } catch (Program\SessionExpiredException) {
                    // kissj no longer knew the session: ended already, which is what was asked
                } catch (\Throwable $e) {
                    Telemetry\Collector::collect($e);
                }
            }
            $auth->logout();
            $this->get(Session::class)->transact(static function (array &$bag) use ($identity): void {
                unset($bag['pairing']);
                // "Odhlášeno" only says something to a reader who was logged in
                if ($identity !== null) {
                    $bag[Kernel::TOAST_KEY] = Kernel::LOGOUT_TOAST + ['at' => time()];
                }
            });

            return $response->withHeader('Location', $base . '/profil')->withStatus(302);
        })->setName('pairing-revoke');

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

    /**
     * The cross-site refusal of a pairing start or a logout, as a page of the app: a reader
     * who hits it (a form left open across a deploy, a privacy extension stripping the
     * headers) is told what to do and given the way back, with the 403 kept.
     */
    private static function loginRefused(
        Twig $twig,
        \Psr\Http\Message\ResponseInterface $response,
        string $base,
        string $message = 'Přihlášení se nepodařilo. Načti stránku a zkus to znovu.',
    ): \Psr\Http\Message\ResponseInterface {
        return $twig->render($response->withStatus(403)->withHeader('Content-Type', 'text/html; charset=utf-8'), 'error.twig', [
            'notFound' => false,
            'details' => null,
            'message' => $message,
            'back' => ['href' => $base . '/profil', 'label' => 'Zpět na profil'],
        ]);
    }

    /**
     * The pending pairing kept in the session by the start, or null when there is none (or
     * it is not one: an older shape, a hand-edited file).
     */
    public static function sessionPairing(Session $session): ?Program\Pairing
    {
        $data = $session->get('pairing');
        if (!is_array($data)) {
            return null;
        }
        foreach (['deviceCode', 'userCode', 'verificationUrl', 'expiresAt'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                return null;
            }
        }
        $expiresAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['expiresAt']);
        if ($expiresAt === false) {
            return null;
        }

        return new Program\Pairing($data['deviceCode'], $data['userCode'], $data['verificationUrl'], $expiresAt, 5);
    }

    /**
     * kissj no longer knows the reader's device session (its 401): the identity is dropped
     * and Profil says why. Any screen whose personal read hits it calls this.
     */
    public static function sessionGone(Auth\Authenticator $auth, Session $session): void
    {
        $auth->logout();
        $session->transact(static function (array &$bag): void {
            $bag['pairingOutcome'] = ['code' => 'gone', 'at' => time()];
            // a login toast not shown yet would now be a lie
            unset($bag[self::TOAST_KEY]);
        });
    }

    /**
     * The toast of a page that found the reader's session gone while it rendered
     * (sessionGone()): that page renders logged out, so it says why itself.
     *
     * @return array{title: string, line: string}
     */
    public static function goneToast(): array
    {
        return ['title' => self::LOGOUT_TOAST['title'], 'line' => self::PAIRING_OUTCOMES['gone']];
    }

    /**
     * The pending server toast — stored as `['title' => string, 'line' => ?string, 'at' =>
     * int]`, plus the `tag` of the write it reports (putToastUnlessPending()) — taken out of
     * the session, or null. Only a full document the reader asked for
     * takes it. A fragment (X-Screen) leaves it, because the loader's warm-up reads every
     * screen as a fragment; so does the worker's own fetch of a whole document
     * (WORKER_FILL_HEADER), because its fill and refill read every screen as a page and as a
     * fragment and would swallow it before the reader sees anything. One older than
     * TOAST_SECONDS is dropped unsaid. A request without one never opens the session, so a
     * logged-out reader gets no session from it.
     *
     * @return array{title: string, line: ?string}|null
     */
    public static function takeToast(\Psr\Http\Message\ServerRequestInterface $request, Session $session, ?int $now = null): ?array
    {
        if ($request->getHeaderLine('X-Screen') === '1'
            || $request->getHeaderLine(self::WORKER_FILL_HEADER) === '1'
            || !$session->has(self::TOAST_KEY)
        ) {
            return null;
        }
        $toast = $session->transact(static function (array &$bag): mixed {
            $toast = $bag[self::TOAST_KEY] ?? null;
            unset($bag[self::TOAST_KEY]);

            return $toast;
        });
        if (!is_array($toast) || !is_string($toast['title'] ?? null) || $toast['title'] === '' || !is_int($toast['at'] ?? null)) {
            return null;
        }
        if (($now ?? time()) - $toast['at'] > self::TOAST_SECONDS) {
            return null;
        }
        $line = $toast['line'] ?? null;

        return ['title' => $toast['title'], 'line' => is_string($line) && $line !== '' ? $line : null];
    }

    /**
     * Leaves a toast in the session for the next full document the reader asks for
     * (takeToast()): what a write said, shown on the page its redirect lands on.
     */
    public static function putToast(Session $session, string $title, ?string $line, ?string $tag = null): void
    {
        $session->set(self::TOAST_KEY, self::toastEntry($title, $line, $tag));
    }

    /**
     * putToast(), unless the toast still waiting in the session carries `$tag` and is not
     * yet too old to be shown: that one stays. A write's success tags its toast, and the
     * same write sent twice (a double tap) is refused the second time; the refusal must not
     * talk over the success the reader has not seen yet. Checked and written under one lock.
     */
    public static function putToastUnlessPending(Session $session, string $title, ?string $line, string $tag): void
    {
        $session->transact(static function (array &$bag) use ($title, $line, $tag): void {
            $pending = $bag[self::TOAST_KEY] ?? null;
            if (is_array($pending) && ($pending['tag'] ?? null) === $tag
                && is_int($pending['at'] ?? null) && time() - $pending['at'] <= self::TOAST_SECONDS
            ) {
                return;
            }
            $bag[self::TOAST_KEY] = self::toastEntry($title, $line, null);
        });
    }

    /** @return array{title: string, line: ?string, at: int, tag?: string} */
    private static function toastEntry(string $title, ?string $line, ?string $tag): array
    {
        $entry = ['title' => $title, 'line' => $line, 'at' => time()];
        if ($tag !== null) {
            $entry['tag'] = $tag;
        }

        return $entry;
    }

    /**
     * Marks a response that carries a toast (TOAST_HEADER), so the worker keeps it out of
     * the offline copy; a response without one is returned as it is.
     *
     * @param array{title: string, line: ?string}|null $toast
     */
    public static function markToast(\Psr\Http\Message\ResponseInterface $response, ?array $toast): \Psr\Http\Message\ResponseInterface
    {
        return $toast === null ? $response : $response->withHeader(self::TOAST_HEADER, '1');
    }

    /** The outcome line Profil shows now, or null: none set, an unknown code, or older than ten minutes. */
    public static function pairingOutcomeLine(Session $session, ?int $now = null): ?string
    {
        $outcome = $session->get('pairingOutcome');
        if (!is_array($outcome) || !is_string($outcome['code'] ?? null) || !is_int($outcome['at'] ?? null)) {
            return null;
        }
        if (($now ?? time()) - $outcome['at'] >= self::PAIRING_OUTCOME_SECONDS) {
            return null;
        }

        return self::PAIRING_OUTCOMES[$outcome['code']] ?? null;
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
