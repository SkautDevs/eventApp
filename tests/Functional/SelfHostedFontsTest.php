<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventCatalog;
use App\Kernel;

/**
 * No font and no icon comes from a third-party origin: an installed app shows every
 * icon and its own typefaces offline, and the browser lane, which resolves no other
 * host, renders the real look.
 */
final class SelfHostedFontsTest extends AppTestCase
{
    /**
     * The font hosts the app used to pull from. `cdn.skauting.cz` rather than the bare
     * domain: the homepage's credit links to devs.skauting.cz, which is a link, not a font.
     */
    private const array HOSTS = ['fontawesome.com', 'cdn.skauting.cz', 'googleapis.com', 'gstatic.com'];

    private const array PATHS = ['/', '/programy', '/mapa', '/novinky', '/odkazy', '/profil', '/offline', '/neexistuje'];

    public function testNoRenderedPageNamesAThirdPartyFontHost(): void
    {
        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $config) {
            $slug = basename(dirname($config));
            $app = $this->createApp($slug);
            foreach (self::PATHS as $path) {
                foreach ([[], ['X-Screen' => '1']] as $headers) {
                    $html = (string) $this->request($app, 'GET', $path, headers: $headers)->getBody();
                    foreach (self::HOSTS as $host) {
                        self::assertStringNotContainsString($host, $html, $slug . $path);
                    }
                }
            }
        }

        $picker = Kernel::createInstance(new EventCatalog($this->eventsDir()), new \DateTimeImmutable('2026-10-06'));
        foreach (['/', '/neexistuje'] as $path) {
            $html = (string) $this->rawRequest($picker, 'GET', $path)->getBody();
            foreach (self::HOSTS as $host) {
                self::assertStringNotContainsString($host, $html, 'instance ' . $path);
            }
        }
    }

    public function testTheLayoutLinksTheVendoredIconsWithoutIntegrityOrCrossorigin(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/')->getBody();

        self::assertStringContainsString('<link rel="stylesheet" href="/vendor/fontawesome-free-5.8.1/css/all.min.css">', $html);
        self::assertStringNotContainsString('integrity=', $html);
        self::assertStringNotContainsString('rel="preconnect"', $html);
    }

    public function testNoFontUrlIsLeftInTheCodeTheTemplatesOrTheEvents(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['src', 'templates', 'events'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }
                self::assertStringNotContainsString('font-url', (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }

    public function testTheStylesheetLoadsNoFontFromAnotherOrigin(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        self::assertDoesNotMatchRegularExpression('~url\(\s*[\'"]?(https?:)?//~i', $css);
    }
}
