<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Container\Definitions;
use App\Environment;
use App\EventCatalog;
use App\EventConfig;
use App\Kernel;
use App\View\InstanceTwigFactory;
use App\View\TwigFactory;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Views\Twig;

/** The container definitions and the Twig environment Kernel builds its apps from. */
final class DefinitionsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    public function testTheEventDefinitionsNameEveryServiceTheAppReads(): void
    {
        $event = EventConfig::load(self::ROOT . '/events', 'obrok19');
        $environment = Environment::fromGlobals();
        $definitions = Definitions::forEvent($event, $environment, self::ROOT);

        foreach ([
            EventConfig::class, \App\Paths::class, Environment::class, ClockInterface::class,
            \App\Program\ProgramProviderInterface::class, \App\Program\Freshness::class,
            \App\Http\AssetVersion::class, \App\Http\ScriptVersion::class, \App\Http\ScriptGraph::class, \App\Session::class, \App\Auth\Authenticator::class,
            \App\Auth\PairingState::class, \App\Toast\Toasts::class, \App\Auth\PairingThrottle::class,
            \App\Push\SubscribeThrottle::class, \PDO::class, \App\Push\SubscriptionRepository::class,
            \App\Push\MessageRepository::class, \App\Inbox\InboxRepository::class, \App\Push\EndpointPolicy::class, \App\Http\ClientIp::class,
            \Psr\Log\LoggerInterface::class, Kernel::ERRORS_LOGGER, \App\Push\PushSenderInterface::class,
            Twig::class,
        ] as $key) {
            self::assertArrayHasKey($key, $definitions, $key);
        }
        self::assertCount(25, $definitions);
        self::assertSame($event, $definitions[EventConfig::class]);
        self::assertSame($environment, $definitions[Environment::class]);
    }

    public function testTheInstanceDefinitionsHoldNothingEventShaped(): void
    {
        $catalog = new EventCatalog(self::ROOT . '/events');
        $environment = Environment::fromGlobals();
        $definitions = Definitions::forInstance($catalog, $environment, self::ROOT);

        self::assertSame([Environment::class, ClockInterface::class, EventCatalog::class, Twig::class, Kernel::ERRORS_LOGGER], array_keys($definitions));
        self::assertSame($catalog, $definitions[EventCatalog::class]);
        self::assertSame($environment, $definitions[Environment::class]);
    }

    public function testTheInstanceTwigCarriesAnEmptyNonceAndNoCache(): void
    {
        $env = new InstanceTwigFactory(self::ROOT)->create()->getEnvironment();

        self::assertSame('', $env->getGlobals()['cspNonce']);
        self::assertFalse($env->getCache());
    }

    public function testTheEventTwigCarriesItsGlobalsFunctionsAndFilter(): void
    {
        $event = EventConfig::load(self::ROOT . '/events', 'obrok19');
        $environment = Environment::fromGlobals();
        $container = new ContainerBuilder()->addDefinitions(Definitions::forEvent($event, $environment, self::ROOT))->build();
        $env = new TwigFactory($event, $environment, self::ROOT)->create($container)->getEnvironment();

        $globals = $env->getGlobals();
        self::assertSame('obrok19', $globals['event']->slug);
        self::assertSame('/obrok19', $globals['base']);
        self::assertFalse($globals['fragment']);
        self::assertSame('/', $globals['screenPath']);
        self::assertSame('', $globals['cspNonce']);
        self::assertArrayHasKey('vapidPublicKey', $globals);
        foreach (['auth_identity', 'data_freshness', 'asset_version'] as $function) {
            self::assertNotNull($env->getFunction($function), $function);
        }
        self::assertNotNull($env->getFilter('dateToCzechDayName'));
        self::assertTrue($env->isAutoReload());
    }
}
