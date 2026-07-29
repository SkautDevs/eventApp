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
            // Session::class => \DI\create(Session::class), // Task 8 restores this
            Twig::class => function () use ($root, $event): Twig {
                $twig = Twig::create($root . '/templates', ['cache' => false]);
                $env = $twig->getEnvironment();
                $env->addGlobal('event', $event->raw + ['slug' => $event->slug]);
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
