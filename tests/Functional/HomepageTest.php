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
        // no visible heading; the box is named for assistive tech only
        self::assertStringContainsString('<section class="emergency" aria-label="V nouzi">', $html);
        self::assertStringNotContainsString('<h2 id="emergency-title"', $html);
        self::assertStringContainsString('<a class="emergency-call" href="tel:+420000000000">', $html);
        self::assertStringNotContainsString('tel:112', $html);
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

    public function testALoggedInParticipantSeesTheirNextProgrammeLinkedIntoMyProgram(): void
    {
        $app = $this->createApp('obrok27');
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'OBROK1']);
        $html = (string) $this->request($app, 'GET', '/')->getBody();

        // all eight of OBROK1's programmes are there for the device clock to choose from
        self::assertSame(8, substr_count($html, 'class="link-card is-highlight next-card"'));
        // in 2027, so the server's pick is the first one, and only it is shown
        self::assertMatchesRegularExpression('#<section class="next-up" aria-label="Tvůj program" data-next-up>#', $html);
        self::assertMatchesRegularExpression('#<a class="link-card is-highlight next-card" href="/obrok27/programy\#muj-program-1" data-key="1" data-start="2027-06-02T12:00:00\+02:00" data-end="[^"]+" data-deep-link>#', $html);
        self::assertSame(7, preg_match_all('#class="link-card is-highlight next-card"[^>]* data-deep-link hidden>#', $html));
        self::assertStringContainsString('Tvůj další program', $html);
        // under the emergency box, above the push button
        self::assertLessThan(strpos($html, 'data-push-toggle'), strpos($html, 'data-next-up'));
        self::assertGreaterThan(strpos($html, 'class="emergency"'), strpos($html, 'data-next-up'));
    }

    public function testEverythingOverHidesTheBox(): void
    {
        $app = $this->createApp('korbo26');
        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'KORBO1']);
        $html = (string) $this->request($app, 'GET', '/')->getBody();

        // korbo26 ended in September 2026: the cards are still sent, for a clock that disagrees
        self::assertStringContainsString('<section class="next-up" aria-label="Tvůj program" data-next-up hidden>', $html);
    }

    public function testLoggedOutThereIsNoNextProgramme(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringNotContainsString('data-next-up', $html);
    }
}
