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
                throw new \RuntimeException('Chybí proměnná prostředí EVENT (viz .env.example)');
            }
            $event = EventConfig::load($root . '/events', $slug);
        }

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            EventConfig::class => $event,
            \App\Program\ProgramProviderInterface::class => fn (): \App\Program\ProgramProviderInterface => new \App\Program\StubProgramProvider($event->dir . '/fixtures'),
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
            Twig::class => function () use ($root, $event): Twig {
                $twig = Twig::create($root . '/templates', ['cache' => false]);
                $env = $twig->getEnvironment();
                $env->addGlobal('event', $event->raw + ['slug' => $event->slug]);
                $env->addGlobal('vapidPublicKey', $_ENV['VAPID_PUBLIC_KEY'] ?? '');
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

        self::registerCoreRoutes($app);
        self::registerModules($app, $container, $event);

        return $app;
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
        $container->get(Twig::class)->getEnvironment()->addGlobal('menu', $menu);
    }
}
