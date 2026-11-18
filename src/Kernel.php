<?php

declare(strict_types=1);

namespace App;

use DI\ContainerBuilder;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
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
     */
    private static function addErrorHandling(App $app, ContainerInterface $container, string $template, bool $debug): void
    {
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

    /**
     * The app for everything that is not an event: the picker at `/`, Slim's own 404
     * for the rest. It knows no event, so its container holds nothing event-shaped.
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
        $app->add(new Http\SecurityHeadersMiddleware());
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
        $dbPath = $environment->pushDbPath($root);
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
        // the container's clock, for the core routes and the screen middleware; each request
        // reads it once
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
        // For the same reason: an error page is exactly the response that must not be
        // sniffed or framed, and it is the one the error middleware answers on its own.
        $app->add(new Http\SecurityHeadersMiddleware());
        // after the security headers, so it too is outside the error middleware and a 404
        // or a 500 carries the policy
        $app->add(new Http\CspMiddleware($twig, Http\CspMiddleware::extrasFor($event)));
        // Last of all, so it is outermost: the error middleware's 500 is measured too.
        // Keep it the final $app->add() — anything added after it would go unmeasured.
        $app->add(new Telemetry\TransactionMiddleware());

        // the reader's address for the pairing start's limit; a plain object, no I/O
        $clientIp = $container->get(Http\ClientIp::class);
        \assert($clientIp instanceof Http\ClientIp);
        self::registerCoreRoutes($app, $clock, $clientIp);
        self::registerModules($app, $container, $event);

        return $app;
    }

    private static function registerCoreRoutes(App $app, ClockInterface $clock, Http\ClientIp $clientIp): void
    {
        $base = $app->getBasePath();

        $app->get('/', function ($request, $response) use ($clock) {
            $event = $this->get(EventConfig::class);
            $now = $clock->now();
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
            $toasts = $this->get(Toast\Toasts::class);
            \assert($toasts instanceof Toast\Toasts);
            $toast = null;
            if ($provider !== null && $identity !== null && $phase !== 'before') {
                try {
                    $mine = $provider->getParticipant($identity->sessionToken)->programmes;
                } catch (Program\SessionExpiredException) {
                    $auth->sessionGone();
                    $identity = null;
                    // this very page renders logged out, so it says why itself
                    $toast = $toasts->gone();
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
                $news = Push\NotificationList::items($this->get(Push\MessageRepository::class)->visible($event->slug), $names, $now);
            }

            return $toasts->mark($this->get(Twig::class)->render($response, 'homepage.twig', [
                'links' => $event->content('links'),
                'home' => Home\HomeModel::build($event, $news, $mine, $sections, $identity !== null, $window, $now),
                'toast' => $toast,
            ]), $toast);
        })->setName('homepage');

        $app->get('/profil', function ($request, $response) {
            $auth = $this->get(Auth\Authenticator::class);
            $pairingState = $this->get(Auth\PairingState::class);
            \assert($pairingState instanceof Auth\PairingState);
            $toasts = $this->get(Toast\Toasts::class);
            \assert($toasts instanceof Toast\Toasts);
            $identity = $auth->identity();
            $pairing = $identity === null ? $pairingState->pending() : null;
            // a login's or a logout's, once
            $toast = $toasts->take($request);

            return $toasts->mark($this->get(Twig::class)->render($response, 'profile.twig', [
                'identity' => $identity === null ? null : [
                    'displayName' => $identity->displayName(),
                    'initials' => $identity->initials(),
                ],
                'pairing' => $pairing === null ? null : [
                    'userCode' => $pairing->formattedUserCode(),
                    'verificationUrl' => $pairing->verificationUrl,
                ],
                // the start's, the poll's or a session's outcome, on every read while it lasts
                'outcome' => $pairingState->outcomeLine(),
                'toast' => $toast,
            ]), $toast);
        })->setName('profile');

        // Starts a pairing for this browser: kissj gives a code the reader enters in KissJ,
        // and Profil polls until it is approved. The device id is the hash of the session ID,
        // so a second start replaces this browser's pending pairing at kissj.
        $app->post('/profil/propojit', function ($request, $response) use ($base, $clientIp) {
            // a cross-site form must not start a pairing the attacker would then approve
            if (!Http\SameOrigin::allows($request)) {
                return self::loginRefused($this->get(Twig::class), $response, $base);
            }
            $session = $this->get(Session::class);
            // already paired: nothing to start, and no attempt to count
            if ($this->get(Auth\Authenticator::class)->isLogged()) {
                return $response->withHeader('Location', $base . '/profil')->withStatus(302);
            }

            Telemetry\Tracer::span('auth.pairing', 'POST /profil/propojit', function () use ($request, $session, $clientIp): void {
                $slug = $this->get(EventConfig::class)->slug;
                $ip = $clientIp->throttleKey($request);
                $throttle = $this->get(Auth\PairingThrottle::class);
                $pairingState = $this->get(Auth\PairingState::class);
                \assert($pairingState instanceof Auth\PairingState);
                // every start is a pending pairing at kissj, which caps them per event
                if ($throttle->tooMany($slug, $ip)) {
                    $pairingState->fail('busy');
                    Telemetry\Tracer::tag('pairing_outcome', 'rate_limited');

                    return;
                }
                $throttle->record($slug, $ip);
                try {
                    $pairing = $this->get(Program\ProgramProviderInterface::class)->startPairing(hash('sha256', $session->id()));
                    $pairingState->begin($pairing);
                    Telemetry\Tracer::tag('pairing_outcome', 'started');
                } catch (\App\Program\ProgramUnavailableException | Program\ProgramDataException) {
                    // never arrived, kissj's cap, or an answer that is not a pairing: one notice
                    $pairingState->fail('unavailable');
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
            $status = Telemetry\Tracer::span('auth.pairing', 'GET /profil/propojit/stav', function (): string {
                $pairingState = $this->get(Auth\PairingState::class);
                \assert($pairingState instanceof Auth\PairingState);
                $pairing = $pairingState->pending();
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
                        $pairingState->approved();
                        $this->get(Auth\Authenticator::class)->store(new Auth\Identity($participant->id, $participant->nickname, $poll->sessionToken));
                        // after the rotation: only the new ID carries it to the Profil the page reloads
                        $this->get(Toast\Toasts::class)->loggedIn();
                        Telemetry\Tracer::tag('pairing_outcome', 'approved');

                        return $poll->status->value;
                    case Program\PairingStatus::Expired:
                        $outcome = $poll->status->value;
                        $pairingState->fail($outcome);
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
            $this->get(Auth\PairingState::class)->drop();
            // "Odhlášeno" only says something to a reader who was logged in
            if ($identity !== null) {
                $this->get(Toast\Toasts::class)->loggedOut();
            }

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
        usort($menu, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return $menu;
    }
}
