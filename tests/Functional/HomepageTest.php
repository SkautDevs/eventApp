<?php

declare(strict_types=1);

namespace Tests\Functional;

final class HomepageTest extends AppTestCase
{
    public function testHomepageRenders(): void
    {
        $response = $this->request($this->createApp(), 'GET', '/');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Obrok 2019', $html);
        self::assertStringContainsString('Krizový telefon', $html);
    }

    public function testAnEventWithoutAFooterLinkRendersNoEmptyLink(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/')->getBody();
        self::assertStringNotContainsString('<a href=""></a>', $html);
    }

    public function testTheAppBarHomeLinkIsNamedForTheEvent(): void
    {
        $app = $this->createApp('korbo26');
        $name = \App\EventConfig::load($this->eventsDir(), 'korbo26')->name;
        self::assertStringContainsString('<span class="sr-only">' . htmlspecialchars($name) . ' – úvod</span>', (string) $this->request($app, 'GET', '/novinky')->getBody());
    }

    public function testLongUrlsWrapInsteadOfBeingClipped(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        foreach (['.news-body', '.sheet-perex', '.pl-perex'] as $selector) {
            self::assertMatchesRegularExpression('/' . preg_quote($selector, '/') . '\s*\{[^}]*overflow-wrap:\s*anywhere/', $css, $selector);
        }
    }

    public function testTheEmergencyBoxSitsUnderTheLogoWithDialableNumbers(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('class="emergency"', $html);
        self::assertStringContainsString('V nouzi', $html);
        self::assertMatchesRegularExpression('#<a class="emergency-call" href="tel:000000000">#', $html);
        // under the logo and above the push button
        self::assertLessThan(strpos($html, 'data-push-toggle'), strpos($html, 'class="emergency"'));
        self::assertGreaterThan(strpos($html, 'mainLogo'), strpos($html, 'class="emergency"'));
    }

    /** Review Focus 5: the real homepage, a number with a prefix and spaces — spaces go, + stays. */
    public function testThePhoneNumberIsDialable(): void
    {
        $html = (string) $this->request($this->createApp('emergency', fixtureEvent: true), 'GET', '/')->getBody();

        self::assertStringContainsString('class="emergency"', $html);
        self::assertStringContainsString('<a class="emergency-call" href="tel:+420000000000">', $html);
        self::assertStringContainsString('<span class="emergency-number">+420 000 000 000</span>', $html);
    }

    public function testNoEmergencyFileMeansNoBox(): void
    {
        $html = (string) $this->request($this->createApp('korbo26'), 'GET', '/')->getBody();

        self::assertStringNotContainsString('class="emergency"', $html);
    }
}
