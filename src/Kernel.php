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
     * An explicit whitelist rather than a cast: (bool) "false" and (bool) "off" are both
     * true, so an operator writing the obvious APP_DEBUG=false would turn debug pages on.
     */
    private static function debug(): bool
    {
        return in_array($_ENV['APP_DEBUG'] ?? '', ['1', 'true', 'on'], true);
    }

    /**
     * Error pages: details only under APP_DEBUG, every real error logged, a 404 or 405 not.
     */
    private static function addErrorHandling(App $app): void
    {
        $debug = self::debug();
        $middleware = $app->addErrorMiddleware(displayErrorDetails: $debug, logErrors: true, logErrorDetails: true);
        $middleware->setDefaultErrorHandler(new QuietErrorHandler($app->getCallableResolver(), $app->getResponseFactory()));
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
            Twig::class => fn (): Twig => Twig::create($root . '/templates', ['cache' => false]),
        ]);
        $builder->addDefinitions($containerOverrides);

        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        self::addErrorHandling($app);
        self::addSecurityHeadersMiddleware($app);

        $app->get('/', function ($request, $response) use ($today) {
            return $this->get(Twig::class)->render($response, 'picker.twig', $this->get(EventCatalog::class)->listed($today));
        })->setName('picker');

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
            \App\Program\ProgramProviderInterface::class => function () use ($event): \App\Program\ProgramProviderInterface {
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

                    return new \App\Program\KissjProgramProvider(
                        http: new \GuzzleHttp\Client(['base_uri' => rtrim($baseUrl, '/') . '/', 'timeout' => 10]),
                        apiKey: $apiKey,
                    );
                }

                return new \App\Program\StubProgramProvider($event->dir . '/fixtures');
            },
            Session::class => fn (): Session => new Session($event->slug),
            Auth\Authenticator::class => \DI\autowire(),
            \App\Push\SubscriptionRepository::class => fn (): \App\Push\SubscriptionRepository => new \App\Push\SubscriptionRepository(self::pushDbPath($root)),
            \App\Push\MessageRepository::class => fn (): \App\Push\MessageRepository => new \App\Push\MessageRepository(self::pushDbPath($root)),
            \App\Push\PushSenderInterface::class => fn (\Psr\Container\ContainerInterface $c): \App\Push\PushSenderInterface => new \App\Push\WebPushSender(
                repository: $c->get(\App\Push\SubscriptionRepository::class),
                vapidPublicKey: $_ENV['VAPID_PUBLIC_KEY'] ?? '',
                vapidPrivateKey: $_ENV['VAPID_PRIVATE_KEY'] ?? '',
                vapidSubject: $_ENV['VAPID_SUBJECT'] ?? '',
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

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        // Every event lives under its own prefix. Slim prepends the base path to every
        // route pattern and to every url_for(), so modules and templates stay unaware of it.
        $base = '/' . $event->slug;
        $app->setBasePath($base);
        // '/obrok19' is what people type; the homepage route is '/obrok19/'
        $app->redirect('', $base . '/', 301);
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(TwigMiddleware::createFromContainer($app, Twig::class));
        self::addErrorHandling($app);
        // Added last, so it is the outermost layer of the stack — outside the error
        // middleware rather than inside it. A thrown 404 or 500 never reaches the
        // route, so a header applied further in would be missing from exactly the
        // responses a shared cache is most likely to keep.
        self::addScreenMiddleware($app, $container);
        // For the same reason: an error page is exactly the response that must not be
        // sniffed or framed, and it is the one the error middleware answers on its own.
        self::addSecurityHeadersMiddleware($app);

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
     * It is the outermost middleware, which is what puts `Vary: X-Screen` on an error
     * response too: Slim's error middleware answers a 404 or a 500 without ever calling
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

            // the two responses differ for the same URL, so anything caching them has to
            // key on the header as well
            return $handler->handle($request)->withHeader('Vary', 'X-Screen');
        });
    }

    /**
     * The response headers that cost nothing and break nothing here: the app serves no
     * user-uploaded file, never frames itself, and asks for none of the powerful
     * features. Deliberately no CSP and no HSTS — the first needs a per-request nonce
     * for the two inline scripts and the inline style block in `_layout.twig`, the
     * second needs TLS to exist first; both are decisions, not omissions.
     */
    private static function addSecurityHeadersMiddleware(App $app): void
    {
        // deliberately not a static closure: Slim binds every middleware Closure to the
        // container, and a static one cannot be bound at all
        $app->add(function ($request, $handler) {
            $response = $handler->handle($request);

            return $response
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Referrer-Policy', 'same-origin')
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
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

            if ($code !== '') {
                $identity = new Auth\Identity(displayName: 'TIE ' . $code, tieCode: $code);
                try {
                    $this->get(\App\Program\ProgramProviderInterface::class)->getProgramsForIdentity($identity);
                    $this->get(Auth\Authenticator::class)->store($identity);
                    $session->delete('tieError');
                } catch (Auth\UnknownParticipantException) {
                    $session->set('tieError', 'Neplatný TIE kód.');
                } catch (\GuzzleHttp\Exception\TransferException) {
                    $session->set('tieError', 'Přihlášení se teď nedaří, zkuste to prosím později.');
                }
            }

            return $response->withHeader('Location', $base . $target)->withStatus(302);
        })->setName('tie-login');

        $app->post('/profil/tie-logout', function ($request, $response) use ($base) {
            $this->get(Auth\Authenticator::class)->logout();

            return $response->withHeader('Location', $base . '/profil')->withStatus(302);
        })->setName('tie-logout');
    }

    private static function registerModules(App $app, ContainerInterface $container, EventConfig $event): void
    {
        $menu = [];
        foreach ($event->features as $feature) {
            $module = $container->get(Module\ModuleRegistry::classFor($feature));
            $module->registerRoutes($app);
            if (($item = $module->menuItem()) !== null) {
                $menu[] = $item + ['key' => $feature];
            }
        }
        // the tab bar reads left to right in its own order, not in the order features are listed
        usort($menu, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $container->get(Twig::class)->getEnvironment()->addGlobal('menu', $menu);
    }
}
