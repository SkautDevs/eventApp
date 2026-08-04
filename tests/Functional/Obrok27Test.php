<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\SkautisGatewayInterface;

final class Obrok27Test extends AppTestCase
{
    public function testObrok27Boots(): void
    {
        $response = $this->request($this->createApp('obrok27'), 'GET', '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Obrok 2027', (string) $response->getBody());
    }

    public function testEveryEnabledFeatureRenders(): void
    {
        $app = $this->createApp('obrok27', overrides: [
            SkautisGatewayInterface::class => new FakeSkautisGateway(),
        ]);
        foreach (['/novinky', '/mapa', '/odkazy', '/programy', '/profil'] as $uri) {
            self::assertSame(200, $this->request($app, 'GET', $uri)->getStatusCode(), $uri);
        }
    }

    public function testVisualIdentityIsLimeBlackAndPurple(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('--color-background: #c2ea3a', $html); // lime
        self::assertStringContainsString('--color-base: #101010', $html);       // dark bars
        self::assertStringContainsString('--color-primary: #6122eb', $html);    // purple accent
    }

    /**
     * The purple accent measures 2.7:1 on the dark tab bar, so the active tab uses
     * lime instead — an event-specific override of the default inverted text colour.
     */
    public function testActiveTabUsesTheLimeOverride(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/novinky')->getBody();

        self::assertStringContainsString('--color-nav-active: #c2ea3a', $html);
        self::assertStringContainsString('class="tab is-active" href="/novinky"', $html);
    }

    public function testHomeTabUsesTheShortEventName(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('<span>Obrok 27</span>', $html);
    }

    public function testLogoIsTheGhost(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();
        self::assertStringContainsString('events/obrok27/ghost.png', $html);

        // the ghost is drawn in lime for the menu so it shows on the black bar
        foreach (['ghost.png', 'ghost-lime.png'] as $file) {
            self::assertFileExists(dirname(__DIR__, 2) . '/www/events/obrok27/' . $file);
        }
    }

    public function testHandbookDownloadMissingFileIs404(): void
    {
        // the 2027 handbook PDF is not uploaded yet → page works, download 404s
        self::assertSame(404, $this->request($this->createApp('obrok27'), 'GET', '/handbook/download')->getStatusCode());
    }
}
