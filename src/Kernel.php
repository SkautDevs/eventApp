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
    public static function create(?EventConfig $event = null, array $containerOverrides = []): App
    {
        $root = dirname(__DIR__);

        if ($event === null) {
            if (is_file($root . '/.env')) {
                \Dotenv\Dotenv::createImmutable($root)->safeLoad();
            }
            $slug = $_ENV['EVENT'] ?? getenv('EVENT');
            if (!is_string($slug) || $slug === '') {
                throw new \RuntimeException('Missing EVENT environment variable (see .env.example)');
            }
            $event = EventConfig::load($root . '/events', $slug);
        }

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            EventConfig::class => $event,
            \App\Program\ProgramProviderInterface::class => function () use ($event): \App\Program\ProgramProviderInterface {
                if (($_ENV['PROGRAM_PROVIDER'] ?? 'stub') === 'kissj') {
                    $baseUrl = $_ENV['KISSJ_BASE_URL'] ?? '';
                    if ($baseUrl === '') {
                        throw new \RuntimeException('PROGRAM_PROVIDER=kissj requires KISSJ_BASE_URL');
                    }

                    return new \App\Program\KissjProgramProvider(
                        http: new \GuzzleHttp\Client(['base_uri' => rtrim($baseUrl, '/') . '/', 'timeout' => 10]),
                        eventSlug: $event->get('kissj')['eventSlug'] ?? $event->slug,
                    );
                }

                return new \App\Program\StubProgramProvider($event->dir . '/fixtures');
            },
            Session::class => \DI\create(Session::class),
            Auth\Authenticator::class => \DI\autowire(),
            Auth\SkautisGatewayInterface::class => fn (): Auth\SkautisGatewayInterface => new Auth\SkautisGateway(
                appId: $_ENV['SKAUTIS_APP_ID'] ?? '',
                testMode: (bool) ($_ENV['SKAUTIS_TEST_MODE'] ?? false),
            ),
            \App\Push\SubscriptionRepository::class => function () use ($root): \App\Push\SubscriptionRepository {
                $path = $_ENV['PUSH_DB_PATH'] ?? 'var/push.sqlite';
                if (!str_starts_with($path, '/')) {
                    $path = $root . '/' . $path;
                }

                return new \App\Push\SubscriptionRepository($path);
            },
            \App\Push\PushSenderInterface::class => fn (\Psr\Container\ContainerInterface $c): \App\Push\PushSenderInterface => new \App\Push\WebPushSender(
                repository: $c->get(\App\Push\SubscriptionRepository::class),
                vapidPublicKey: $_ENV['VAPID_PUBLIC_KEY'] ?? '',
                vapidPrivateKey: $_ENV['VAPID_PRIVATE_KEY'] ?? '',
                vapidSubject: $_ENV['VAPID_SUBJECT'] ?? '',
            ),
            Twig::class => function (ContainerInterface $c) use ($root, $event): Twig {
                $twig = Twig::create($root . '/templates', ['cache' => false]);
                $env = $twig->getEnvironment();
                // 'theme' and 'roles' are spread in explicitly so they are arrays even for
                // an event whose config never mentions them — the layout iterates them unguarded
                $env->addGlobal('event', $event->raw + [
                    'slug' => $event->slug,
                    'theme' => $event->theme,
                    'roles' => $event->roles,
                ]);
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
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(TwigMiddleware::createFromContainer($app, Twig::class));
        $app->addErrorMiddleware(
            displayErrorDetails: (bool) ($_ENV['APP_DEBUG'] ?? false),
            logErrors: true,
            logErrorDetails: true,
        );
        // Added last, so it is the outermost layer of the stack — outside the error
        // middleware rather than inside it. A thrown 404 or 500 never reaches the
        // route, so a header applied further in would be missing from exactly the
        // responses a shared cache is most likely to keep.
        self::addScreenMiddleware($app, $container);

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

    private static function registerCoreRoutes(App $app): void
    {
        $app->get('/', function ($request, $response) {
            return $this->get(Twig::class)->render($response, 'homepage.twig', [
                'links' => $this->get(EventConfig::class)->content('links'),
            ]);
        })->setName('homepage');

        $app->post('/', function ($request, $response) {
            $body = (array) $request->getParsedBody();
            $auth = $this->get(Auth\Authenticator::class);

            if (!empty($body['skautIS_Token'])) {
                $auth->store($this->get(Auth\SkautisGatewayInterface::class)->loginFromPost($body));
            } elseif (!empty($body['skautIS_Logout'])) {
                $auth->logout();
            }

            $returnUrl = $request->getQueryParams()['ReturnUrl'] ?? '/';

            return $response->withHeader('Location', $returnUrl)->withStatus(302);
        });

        $app->get('/profil', function ($request, $response) {
            $auth = $this->get(Auth\Authenticator::class);
            $gateway = $this->get(Auth\SkautisGatewayInterface::class);
            $session = $this->get(Session::class);
            $tieError = $session->get('tieError');
            $session->delete('tieError');

            return $this->get(Twig::class)->render($response, 'profile.twig', [
                'isLogged' => $auth->isLogged(),
                'identity' => $auth->identity()?->displayName,
                'loginUrl' => $gateway->getLoginUrl('/profil'),
                'logoutUrl' => $gateway->getLogoutUrl('/profil'),
                'tieError' => $tieError,
            ]);
        })->setName('profile');

        $app->post('/profil/tie', function ($request, $response) {
            $code = strtoupper(trim((string) (((array) $request->getParsedBody())['tieCode'] ?? '')));
            $session = $this->get(Session::class);

            if ($code !== '') {
                $identity = new Auth\Identity(type: 'tie', displayName: 'TIE ' . $code, tieCode: $code);
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

            return $response->withHeader('Location', '/profil')->withStatus(302);
        })->setName('tie-login');

        $app->post('/profil/tie-logout', function ($request, $response) {
            $this->get(Auth\Authenticator::class)->logout();

            return $response->withHeader('Location', '/profil')->withStatus(302);
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
