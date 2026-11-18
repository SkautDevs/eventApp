<?php

declare(strict_types=1);

namespace App\View;

use App\Auth;
use App\Environment;
use App\EventConfig;
use App\Module;
use App\Module\Home\HomeModel;
use App\Http;
use App\Program;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use Twig\TwigFilter;

/**
 * An event's Twig environment, with the globals the layout reads (`event`, `base`,
 * `vapidPublicKey`, the script entries `scripts`, and `fragment`, `screenPath` and
 * `cspNonce`, which the per-request middlewares overwrite), the functions `auth_identity`,
 * `data_freshness`, `asset_version`, `script_url` and `script_preloads` (the files the
 * entries import) and the `dateToCzechDayName` filter. The instance
 * app's plain one is InstanceTwigFactory's.
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
        // the typed config itself: `theme` and both role sets are arrays even for an event
        // whose config never mentions them, because the layout iterates them unguarded
        $env->addGlobal('event', $event);
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
            function () use ($c): ?Auth\IdentityView {
                $auth = $c->get(Auth\Authenticator::class);
                // the event container autowires the class under its own name (Definitions::forEvent())
                \assert($auth instanceof Auth\Authenticator);
                $identity = $auth->identity();

                return $identity === null ? null : Auth\IdentityView::of($identity);
            },
        ));
        // The age of the provider data on this screen. A function rather than a
        // global for the same reason as auth_identity(): the handler fills it while
        // it runs, before the layout renders.
        $env->addFunction(new \Twig\TwigFunction(
            'data_freshness',
            function () use ($c): Program\FreshnessView {
                $freshness = $c->get(Program\Freshness::class);
                // the event container builds the class under its own name (Definitions::forEvent())
                \assert($freshness instanceof Program\Freshness);

                return $freshness->forView();
            },
        ));
        // `css/base.css` → `css/base.css?v=<first 8 hex of its sha256>`: the URL changes
        // exactly when the bytes do, so nothing is bumped by hand
        $env->addFunction(new \Twig\TwigFunction(
            'asset_version',
            function (string $path) use ($c): string {
                $assets = $c->get(Http\AssetVersion::class);
                // the event container builds the class under its own name (Definitions::forEvent())
                \assert($assets instanceof Http\AssetVersion);

                return $assets->url($path);
            },
        ));
        // `shell/index.js` → `/js/<first 8 hex of the www/js/ tree's sha256>/shell/index.js`: one version
        // for every script, so a phone never mixes the scripts of two deploys
        $env->addFunction(new \Twig\TwigFunction(
            'script_url',
            function (string $path) use ($c): string {
                $scripts = $c->get(Http\ScriptVersion::class);
                // the event container builds the class under its own name (Definitions::forEvent())
                \assert($scripts instanceof Http\ScriptVersion);

                return $scripts->url($path);
            },
        ));
        // The layout's scripts: `scripts`, one module entry per module with a script in module
        // order (Modules::scripts()), and `script_preloads()`, every file those entries import,
        // each once in the order first met, so the browser fetches the whole graph at once
        // rather than one import level per round trip. A function, not a global: only the
        // layout calls it, so a fragment (X-Screen) never reads the import graph.
        $modules = $c->get(Module\Modules::class);
        // the event container autowires the class under its own name
        \assert($modules instanceof Module\Modules);
        $scripts = $modules->scripts();
        $env->addGlobal('scripts', $scripts);
        $env->addFunction(new \Twig\TwigFunction(
            'script_preloads',
            /** @return list<string> */
            function () use ($c, $scripts): array {
                $graph = $c->get(Http\ScriptGraph::class);
                // the event container builds the class under its own name (Definitions::forEvent())
                \assert($graph instanceof Http\ScriptGraph);
                $preloads = [];
                foreach ($scripts as $entry) {
                    foreach ($graph->imports($entry) as $file) {
                        $preloads[$file] = true;
                    }
                }

                return array_keys($preloads);
            },
        ));
        // the core stylesheets, each a path under www/ for asset_version(), in link order
        // (Modules::sheets()): the event's own sheet is linked after them by the layout
        $env->addGlobal('stylesheets', $modules->sheets());
        $env->addFilter(new TwigFilter('dateToCzechDayName', function (\DateTimeInterface $datetime): string {
            return HomeModel::WEEKDAYS[(int) $datetime->format('N')];
        }));

        return $twig;
    }
}
