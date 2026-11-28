<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * The whole route table of the event with every feature, as Slim reports it: method,
 * pattern without the base path, name. Sentry names its transactions by the pattern and
 * the templates call url_for() by the name, and neither shows in a rendered page, so a
 * renamed route is caught here and nowhere else.
 */
final class RouteTableTest extends AppTestCase
{
    public function testObrok27sRoutesAreTheseAndNoOthers(): void
    {
        $routes = [];
        foreach ($this->createApp('obrok27')->getRouteCollector()->getRoutes() as $route) {
            $routes[] = implode(' ', $route->getMethods()) . ' ' . $route->getPattern() . ' ' . ($route->getName() ?? '-');
        }
        sort($routes);
        self::assertSame([
            'GET  -',
            'GET / homepage',
            'GET /admin/notify admin-notify',
            'GET /handbook/download handbook-download',
            'GET /informace info',
            'GET /informace/zpravy inbox',
            'GET /mapa map',
            'GET /mapa/plan map-plan',
            'GET /offline offline',
            'GET /precache.json precache',
            'GET /profil profile',
            'GET /profil/propojit/stav pairing-poll',
            'GET /programy programs',
            'GET /programy/moje programs-mine',
            'GET /programy/prehled programs-overview',
            'POST /admin/inbox/{id:[0-9]+}/delete admin-inbox-delete',
            'POST /admin/inbox/{id:[0-9]+}/handled admin-inbox-handled',
            'POST /admin/notify -',
            'POST /admin/notify/{id:[0-9]+}/hidden admin-notify-hidden',
            'POST /informace/zpravy inbox-send',
            'POST /profil/kontrola pairing-check',
            'POST /profil/odpojit pairing-revoke',
            'POST /profil/propojit pairing-start',
            'POST /programy/{id:[0-9]+}/odhlasit program-unregister',
            'POST /programy/{id:[0-9]+}/prihlasit program-register',
            'POST /push/subscribe push-subscribe',
        ], $routes);
        self::assertSame('/obrok27', $this->createApp('obrok27')->getBasePath());
    }
}
