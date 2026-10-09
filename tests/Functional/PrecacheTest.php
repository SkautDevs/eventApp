<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventConfig;
use App\Http\AssetVersion;
use App\Http\Precache;

/** What the service worker keeps, as the server says it. */
final class PrecacheTest extends AppTestCase
{
    /** @return array{version: string, documents: list<string>, assets: list<string>, optional: list<string>} */
    private function listFor(string $slug, bool $fixtureEvent = false): array
    {
        $response = $this->request($this->createApp($slug, fixtureEvent: $fixtureEvent), 'GET', '/precache.json');
        self::assertSame(200, $response->getStatusCode(), $slug);

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testTheListNamesEveryScreenOfTheEvent(): void
    {
        self::assertSame(
            ['/obrok19/', '/obrok19/programy', '/obrok19/mapa', '/obrok19/novinky', '/obrok19/odkazy', '/obrok19/profil', '/obrok19/offline'],
            $this->listFor('obrok19')['documents'],
        );
        self::assertSame(
            ['/korbo26/', '/korbo26/programy', '/korbo26/novinky', '/korbo26/profil', '/korbo26/offline'],
            $this->listFor('korbo26')['documents'],
        );
    }

    public function testEveryScriptAndTheStylesheetCarryTheirContentHash(): void
    {
        $assets = $this->listFor('obrok27')['assets'];

        foreach (['style.css', 'app.js', 'shell.js', 'push.js', 'programs.js'] as $file) {
            $hash = substr((string) hash_file('sha256', dirname(__DIR__, 2) . '/www/' . $file), 0, 8);
            self::assertContains('/' . $file . '?v=' . $hash, $assets, $file);
        }
        self::assertContains('/events/obrok27/site.webmanifest', $assets);
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/www/events/obrok27/site.webmanifest'), true);
        foreach ($manifest['icons'] as $icon) {
            self::assertContains($icon['src'], $assets, $icon['src']);
        }
        self::assertContains('/events/obrok27/ghost-160.png', $assets);
    }

    /**
     * Review Focus 2: one 404 in cache.addAll() fails the worker's whole install. A file
     * that is missing is left out of the list — and no shipped event is missing one, so
     * nothing it names is silently dropped either.
     */
    public function testNoListedFileIsMissingAndNoNamedFileIsDropped(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (glob($root . '/events/*/config.php') ?: [] as $config) {
            $slug = basename(dirname($config));
            $assets = $this->listFor($slug)['assets'];
            self::assertSame(array_values(array_unique($assets)), $assets, $slug . ': listed twice');
            foreach ($assets as $url) {
                self::assertFileExists($root . '/www' . parse_url($url, PHP_URL_PATH), $slug);
            }

            $event = EventConfig::load($root . '/events', $slug);
            $named = array_filter((array) $event->get('assets', []), static fn ($path): bool => is_string($path) && $path !== '');
            $footer = $event->get('homepage')['footerLogo'] ?? null;
            if (is_string($footer) && $footer !== '') {
                $named[] = $footer;
            }
            foreach ($named as $path) {
                self::assertContains('/' . $path, $assets, $slug . ' names ' . $path . ' but it is not in the list');
            }
        }

        // a fixture event ships no files at all: the scripts and the shell's fonts, and
        // nothing that would 404
        $minimal = $this->listFor('minimal', fixtureEvent: true)['assets'];
        self::assertCount(4 + 7, $minimal);
        foreach ($minimal as $url) {
            self::assertMatchesRegularExpression('~^/((style\.css|app\.js|shell\.js|push\.js)\?v=[0-9a-f]{8}|fonts/.+\.woff2|vendor/fontawesome-free-5\.8\.1/.+)$~', $url);
        }
    }

    /** The offline set carries the fonts the event renders with, and only those. */
    public function testTheListCarriesTheEventsFonts(): void
    {
        $fontAwesome = [
            '/vendor/fontawesome-free-5.8.1/css/all.min.css',
            '/vendor/fontawesome-free-5.8.1/webfonts/fa-solid-900.woff2',
            '/vendor/fontawesome-free-5.8.1/webfonts/fa-regular-400.woff2',
            '/vendor/fontawesome-free-5.8.1/webfonts/fa-brands-400.woff2',
        ];
        $root = dirname(__DIR__, 2) . '/www';

        $obrok27 = $this->listFor('obrok27')['assets'];
        foreach (['/fonts/montserrat/montserrat-v31-latin.woff2', '/fonts/montserrat/montserrat-v31-latin-ext.woff2', ...$fontAwesome] as $file) {
            self::assertContains($file, $obrok27);
        }
        self::assertSame([], preg_grep('~^/fonts/(themix|skautbold)/~', $obrok27), 'obrok27 renders neither themix nor skautbold');

        $korbo26 = $this->listFor('korbo26')['assets'];
        foreach (['/fonts/themix/TheMix_LT_400.woff2', '/fonts/themix/TheMix_LT_700.woff2', '/fonts/skautbold/skaut-bold-webfont.woff2', ...$fontAwesome] as $file) {
            self::assertContains($file, $korbo26);
        }
        self::assertSame([], preg_grep('~^/fonts/montserrat/~', $korbo26), 'korbo26 does not render Montserrat');

        foreach ([...$obrok27, ...$korbo26] as $url) {
            if (preg_match('~^/(fonts|vendor)/~', $url) === 1) {
                self::assertFileExists($root . $url);
            }
        }
    }

    public function testTheHandbookIsOptionalAndOnlyWhenItsFileExists(): void
    {
        self::assertSame(['/obrok19/handbook/download'], $this->listFor('obrok19')['optional']);
        // obrok27's PDF is still a TODO of the organisers
        self::assertSame([], $this->listFor('obrok27')['optional']);
        // korbo26 has no handbook at all
        self::assertSame([], $this->listFor('korbo26')['optional']);
    }

    public function testTheVersionChangesExactlyWhenAnAssetsContentDoes(): void
    {
        $dir = sys_get_temp_dir() . '/precache-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/app.js', 'one');
        $versions = new AssetVersion($dir);
        $documents = ['/x/', '/x/programy', '/x/profil', '/x/offline'];

        try {
            $first = Precache::version($documents, ['/' . $versions->url('app.js'), '/events/x/logo.png'], []);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $first);
            self::assertSame($first, Precache::version(array_reverse($documents), ['/events/x/logo.png', '/' . $versions->url('app.js')], []), 'order does not matter');

            file_put_contents($dir . '/app.js', 'two');
            touch($dir . '/app.js', time() + 10);
            self::assertNotSame($first, Precache::version($documents, ['/' . $versions->url('app.js'), '/events/x/logo.png'], []));
        } finally {
            unlink($dir . '/app.js');
            rmdir($dir);
        }
    }

    public function testTheVersionChangesWhenADocumentOrAnOptionalEntryDoes(): void
    {
        $documents = ['/x/', '/x/profil', '/x/offline'];
        $assets = ['/style.css?v=00000000'];
        $first = Precache::version($documents, $assets, []);

        // a tab enabled, a handbook added: the worker learns of either only through the version
        self::assertNotSame($first, Precache::version([...$documents, '/x/novinky'], $assets, []));
        self::assertNotSame($first, Precache::version($documents, $assets, ['/x/handbook/download']));
        // an entry is not the same list in another place
        self::assertNotSame(Precache::version(['/x/a'], $assets, []), Precache::version([], $assets, ['/x/a']));
    }

    public function testTheListIsJsonThatNoCacheKeepsAndItsVersionIsItsLists(): void
    {
        $response = $this->request($this->createApp('korbo26'), 'GET', '/precache.json');
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['version', 'documents', 'assets', 'optional'], array_keys($body));
        self::assertSame(Precache::version($body['documents'], $body['assets'], $body['optional']), $body['version']);
        self::assertStringNotContainsString('\/', (string) $response->getBody());
    }

    public function testTheOfflinePageWearsTheEventAndPointsHome(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/offline');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Jsi offline a tahle stránka ještě není uložená.', $html);
        self::assertStringContainsString('Zkus to znovu, až budeš mít signál.', $html);
        self::assertStringContainsString('<a class="highlight" href="/obrok19/">Zpět na úvod</a>', $html);
        self::assertStringContainsString('<title>Offline · Obrok 2019</title>', $html);
        self::assertStringNotContainsString('aria-current="page"', $html, 'no tab is active');
    }
}
