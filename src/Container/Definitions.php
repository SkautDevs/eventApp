<?php

declare(strict_types=1);

namespace App\Container;

use App\Auth;
use App\Cache;
use App\Environment;
use App\EventCatalog;
use App\EventConfig;
use App\Http;
use App\Kernel;
use App\Paths;
use App\Program;
use App\Session;
use App\Storage;
use App\Time;
use App\Toast;
use App\View\InstanceTwigFactory;
use App\View\TwigFactory;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;

/**
 * The PHP-DI definitions Kernel builds its two kinds of app from: one event's services,
 * and the instance app's few. Nothing is built here: every entry is a value, a closure or
 * an autowire rule PHP-DI resolves on the first get(). The Environment passed in is only
 * the container's own entry; every definition that reads the configuration takes the
 * container's Environment by type, so a container override reaches every reader.
 */
final class Definitions
{
    /** @return array<string, mixed> */
    public static function forEvent(EventConfig $event, Environment $environment, string $root): array
    {
        return [
            EventConfig::class => $event,
            // the project root and the document root, for an action that needs a file path
            Paths::class => new Paths($root, $root . '/www'),
            // every definition below reads the container's, so an override reaches them all
            Environment::class => $environment,
            // the one source of "now": every reader of the time gets it from here
            ClockInterface::class => \DI\get(Time\SystemClock::class),
            \App\Program\ProgramProviderInterface::class => function (ClockInterface $clock, Environment $environment, Program\Freshness $freshness) use ($event, $root): \App\Program\ProgramProviderInterface {
                $programSource = $environment->programSource($event->slug);
                if ($programSource !== null) {
                    // kissj resolves the event from the key, so the key is what picks the event
                    $apiKey = $environment->forEvent($event->slug, 'KISSJ_API_KEY');

                    // Only kissj is cached: the stub's fixture files already are a local copy.
                    // One timeout for every endpoint — a shorter one on the participant call
                    // would reject a slow-but-working kissj on the one call (login) that has no
                    // entry to fall back to, and with the cache a slow kissj is paid once per TTL.
                    return new Program\CachingProgramProvider(
                        inner: new \App\Program\KissjProgramProvider(
                            http: new \GuzzleHttp\Client(['base_uri' => $programSource . '/', 'timeout' => 10]),
                            apiKey: $apiKey,
                        ),
                        cache: new Cache\FileCache($root . '/var/cache/' . $event->slug, $clock),
                        freshness: $freshness,
                        ttl: $environment->programCacheTtl(),
                        clock: $clock,
                    );
                }

                return new \App\Program\LocalProgramProvider($event->dir . '/fixtures', $environment->stubStatePath($root), $clock);
            },
            // how old this request's provider data is; the screen middleware resets it per request
            Program\Freshness::class => fn(ClockInterface $clock): Program\Freshness => new Program\Freshness($clock),
            // content hashes for the stylesheet and the scripts: asset_version() in the
            // layout, and the service worker's precache list (Task 5)
            Http\AssetVersion::class => fn(): Http\AssetVersion => new Http\AssetVersion($root . '/www'),
            // var/sessions (or SESSION_PATH), created 0700 on first use: nothing about sessions
            // depends on the host's ini (the FTP host's handler and defaults are unknown)
            Session::class => fn(ClockInterface $clock, Environment $environment): Session => new Session($event->slug, $environment->sessionPath($root), appRoot: $root, clock: $clock),
            Auth\Authenticator::class => \DI\autowire(),
            // the pending pairing and its outcome, and the pending toast: the session's and
            // the clock's, both resolved by type
            Auth\PairingState::class => \DI\autowire(),
            Toast\Toasts::class => \DI\autowire(),
            // the clock is an optional parameter, which autowiring would leave at its default.
            // Lazy, like the two repositories below: an action takes them in its constructor,
            // and the database opens on the first method call, not when the action is built
            // (PHP-DI builds a native lazy proxy on PHP 8.4 and later).
            Auth\PairingThrottle::class => \DI\autowire()->lazy()->constructorParameter('clock', \DI\get(ClockInterface::class)),
            \App\Push\SubscribeThrottle::class => \DI\autowire()->lazy()->constructorParameter('clock', \DI\get(ClockInterface::class)),
            // One connection per request, opened and migrated on first use only: PHP-DI
            // builds a definition on the first get(), so the homepage, the map, the links
            // and the handbook never touch the file. A request that does pays one
            // `PRAGMA user_version` read; it stays per request because the Apache path
            // has no deploy step to hook a migration into.
            \PDO::class => function (Environment $environment) use ($root): \PDO {
                $pdo = Storage\Database::open($environment->pushDbPath($root));
                (new Storage\Migrator())->migrate($pdo);

                return $pdo;
            },
            \App\Push\SubscriptionRepository::class => \DI\autowire()->lazy()->constructorParameter('clock', \DI\get(ClockInterface::class)),
            \App\Push\MessageRepository::class => \DI\autowire()->lazy()->constructorParameter('clock', \DI\get(ClockInterface::class)),
            // the built-in push services plus PUSH_ENDPOINT_HOSTS (a test's or a dev setup's)
            \App\Push\EndpointPolicy::class => fn(Environment $environment): \App\Push\EndpointPolicy => new \App\Push\EndpointPolicy([
                ...\App\Push\EndpointPolicy::DEFAULT_HOSTS,
                ...$environment->pushEndpointHosts(),
            ]),
            // the reader's address, behind TRUSTED_PROXY_COUNT proxies (and TRUSTED_PROXIES)
            Http\ClientIp::class => fn(Environment $environment): Http\ClientIp => new Http\ClientIp($environment->trustedProxyCount(), $environment->trustedProxies()),
            // Neither logger has a WebProcessor: request data reaches Sentry through the
            // SDK's own request integration, where the Scrubber sees it. Sentry gets an
            // issue only from warning up: the routine push.sent line is a log record, and
            // the counts it carries live on the push.send span; push.failed is a warning.
            \Psr\Log\LoggerInterface::class => fn(): \Psr\Log\LoggerInterface => new \Monolog\Logger('eventapp', [
                new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Info),
                new \Sentry\Monolog\LogToSentryIssueHandler(\Sentry\SentrySdk::getCurrentHub(), \Monolog\Level::Warning),
            ]),
            Kernel::ERRORS_LOGGER => fn(): \Psr\Log\LoggerInterface => self::errorsLogger(),
            \App\Push\PushSenderInterface::class => fn(
                \Psr\Container\ContainerInterface $c,
                Environment $environment,
                \App\Push\EndpointPolicy $endpoints,
                \Psr\Log\LoggerInterface $logger,
            ): \App\Push\PushSenderInterface => new \App\Push\WebPushSender(
                // resolved on the first send: building the sender must not open the database
                repository: static function () use ($c): \App\Push\SubscriptionRepository {
                    $repository = $c->get(\App\Push\SubscriptionRepository::class);
                    // defined above under its own name, autowired (a lazy proxy of the class)
                    \assert($repository instanceof \App\Push\SubscriptionRepository);

                    return $repository;
                },
                endpoints: $endpoints,
                vapidPublicKey: $environment->vapid()['public'],
                vapidPrivateKey: $environment->vapid()['private'],
                vapidSubject: $environment->vapid()['subject'],
                logger: $logger,
            ),
            Twig::class => fn(ContainerInterface $c, Environment $environment): Twig => new TwigFactory($event, $environment, $root)->create($c),
        ];
    }

    /**
     * The instance app knows no event, so its container holds nothing event-shaped.
     *
     * @return array<string, mixed>
     */
    public static function forInstance(EventCatalog $catalog, Environment $environment, string $root): array
    {
        return [
            Environment::class => $environment,
            ClockInterface::class => \DI\get(Time\SystemClock::class),
            EventCatalog::class => $catalog,
            Twig::class => fn(): Twig => new InstanceTwigFactory($root)->create(),
            Kernel::ERRORS_LOGGER => fn(): \Psr\Log\LoggerInterface => self::errorsLogger(),
        ];
    }

    /** The error handler's own channel: the exception already went to Sentry through the Collector. */
    private static function errorsLogger(): \Psr\Log\LoggerInterface
    {
        return new \Monolog\Logger('errors', [new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Level::Info)]);
    }
}
