<?php

declare(strict_types=1);

namespace App\View;

use App\Auth;
use App\Environment;
use App\EventConfig;
use App\Home;
use App\Http;
use App\Program;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use Twig\TwigFilter;

/**
 * An event's Twig environment, with the globals the layout reads (`event`, `base`,
 * `vapidPublicKey`, and `fragment`, `screenPath` and `cspNonce`, which the per-request
 * middlewares overwrite), the functions `auth_identity`, `data_freshness` and
 * `asset_version` and the `dateToCzechDayName` filter. The instance app's plain one is
 * InstanceTwigFactory's.
 */
final readonly class TwigFactory
{
    public function __construct(
        private EventConfig $event,
        private Environment $environment,
        private string $root,
    ) {}

    public function create(ContainerInterface $container): Twig
    {
        // the names the body had as the container's closure in Kernel
        $c = $container;
        $event = $this->event;
        $environment = $this->environment;
        $root = $this->root;

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
        $env->addGlobal('vapidPublicKey', $environment->vapid()['public']);
        // Registered here so the per-request middleware (ScreenMiddleware) can *update* them:
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
            fn(): array => $c->get(Program\Freshness::class)->forView(),
        ));
        // `style.css` → `style.css?v=<first 8 hex of its sha256>`: the URL changes
        // exactly when the bytes do, so nothing is bumped by hand
        $env->addFunction(new \Twig\TwigFunction(
            'asset_version',
            fn(string $path): string => $c->get(Http\AssetVersion::class)->url($path),
        ));
        $env->addFilter(new TwigFilter('dateToCzechDayName', function (array $datetimeArray): string {
            return Home\HomeModel::WEEKDAYS[(int) (new \DateTime($datetimeArray['date']))->format('N')];
        }));

        return $twig;
    }
}
