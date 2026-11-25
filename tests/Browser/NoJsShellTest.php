<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Toast\Toasts;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shell without JavaScript, on obrok19 (light only) and obrok27 (light and dark): every
 * tab is a page of its own, the notice says what needs a script, the parts that only a
 * script drives are hidden, and a server toast fades by CSS alone. Domů's "Můj program"
 * is read on korbo26, the event whose fixture participant has programmes, under a server
 * clock frozen inside its camp.
 */
#[Group('browser')]
final class NoJsShellTest extends NoJsTestCase
{
    private const array EVENTS = ['obrok19', 'obrok27'];

    private const string NOTICE = 'Bez JavaScriptu nefungují notifikace, offline režim, instalace ani volba vzhledu.';

    /**
     * Every tab followed as a link opens its page; on Program every view pill and on Místa
     * every map pill is followed too, each landing on its view's address with that view
     * alone on show (an event with one Místa view has no pills: its one view is shown).
     */
    public function testEveryTabAndViewOpensAsAPage(): void
    {
        foreach (self::EVENTS as $slug) {
            self::visit('/' . $slug . '/');
            $hrefs = array_map(
                static fn($tab): string => (string) parse_url((string) $tab->getAttribute('href'), PHP_URL_PATH),
                self::$browser->findElements(WebDriverBy::cssSelector('.tabbar a.tab')),
            );
            self::assertGreaterThanOrEqual(4, count($hrefs), $slug . ': the tab bar');

            foreach ($hrefs as $href) {
                self::follow('.tabbar a.tab[href="' . $href . '"]');

                self::assertSame($href, self::path(), $slug . ': the tab is a link to its page');
                self::assertSame($href, self::$browser->findElement(WebDriverBy::cssSelector('main > section.screen'))->getAttribute('data-screen'), $slug);
                self::assertSame(
                    'page',
                    self::$browser->findElement(WebDriverBy::cssSelector('.tabbar a.tab[href="' . $href . '"]'))->getAttribute('aria-current'),
                    $slug . ': ' . $href . ' is the current tab',
                );
                if (self::present('.pg[data-pg-root]')) {
                    self::walkPills($slug, '.tabs-tab[data-pg-view]', 'data-pg-view', '.pg', '#pg-view-');
                }
                if (self::present('.tabs-tab[data-map-view]')) {
                    self::walkPills($slug, '.tabs-tab[data-map-view]', 'data-map-view', '.mapview', '#map-view-');
                } elseif (self::present('[data-map], [data-plan]')) {
                    // Místa with one view: no pills, and its one view is the one on show
                    self::assertTrue(self::displayed('[data-map], [data-plan]'), $slug . ': the one Místa view');
                }
            }
        }
    }

    /**
     * Follows each pill matching $pills on the page shown, in their order: it lands on its
     * href, $root paints the view its $attribute names, that pill is the current page, and
     * that view's container ($container + view) is the only one displayed.
     */
    private static function walkPills(string $slug, string $pills, string $attribute, string $root, string $container): void
    {
        $views = array_map(
            static fn($pill): array => [(string) $pill->getAttribute($attribute), (string) parse_url((string) $pill->getAttribute('href'), PHP_URL_PATH)],
            self::$browser->findElements(WebDriverBy::cssSelector($pills)),
        );
        self::assertGreaterThanOrEqual(2, count($views), $slug . ': the pills on ' . self::path());

        foreach ($views as [$view, $href]) {
            self::follow($pills . '[' . $attribute . '="' . $view . '"]');

            self::assertSame($href, self::path(), $slug . ': the ' . $view . ' pill is a link to its address');
            self::assertSame($view, self::attribute($root, 'data-view'), $slug . ': ' . $href . ' paints the view it names');
            self::assertSame('page', self::attribute($pills . '[' . $attribute . '="' . $view . '"]', 'aria-current'), $slug . ': ' . $view . ' is the current page');
            foreach ($views as [$other]) {
                self::assertSame($other === $view, self::displayed($container . $other), $slug . ' on ' . $view . ': the ' . $other . ' view');
            }
        }
    }

    public function testTheNoticeNamesWhatNeedsAScript(): void
    {
        foreach (self::EVENTS as $slug) {
            self::visit('/' . $slug . '/');

            self::assertSame(self::NOTICE, self::text('.noscript'), $slug);
        }
    }

    /** What only a script can drive is not offered: the install card, the mode control, the network banner. */
    public function testScriptOnlyPartsAreHidden(): void
    {
        foreach (self::EVENTS as $slug) {
            foreach (['/' . $slug . '/', '/' . $slug . '/profil'] as $path) {
                self::visit($path);

                self::assertFalse(self::displayed('[data-install-card]'), $path . ': the install card');
                self::assertFalse(self::displayed('[data-netstate]'), $path . ': the network banner');
            }
            // on the page that carries it: obrok27 has a dark set, obrok19 none
            if ($slug === 'obrok27') {
                self::assertFalse(self::displayed('[data-mode-control]'), $slug . ': the mode control');
            } else {
                self::assertCount(0, self::$browser->findElements(WebDriverBy::cssSelector('[data-mode-control]')), $slug);
            }

            self::visit('/' . $slug . '/mapa');
            $offlines = self::$browser->findElements(WebDriverBy::cssSelector('[data-map-offline]'));
            self::assertNotEmpty($offlines, $slug . ': the map\'s offline line is on the page');
            foreach ($offlines as $offline) {
                self::assertFalse($offline->isDisplayed(), $slug . ': the map\'s offline line');
            }
        }
    }

    /**
     * Domů's "Můj program" as the server drew it, read without a script: korbo26, paired the
     * no-JS way, with the server's clock frozen at 13:30 on 18 Sep, inside the camp (the
     * instant HomepageTest uses). On the real clock every shipped event is over or not
     * started and the box is never shown. The box is displayed with the running programme
     * and the next two, each with its lead; the ones already over are not.
     */
    public function testDomuShowsTheRunningProgrammesCard(): void
    {
        self::freezeServerClock('2026-09-18T13:30:00+02:00');
        self::pairWithoutScript('korbo26');

        self::visit('/korbo26/');

        self::assertTrue(self::displayed('[data-next-up]'), 'the box is shown');
        $leads = [];
        foreach (self::$browser->findElements(WebDriverBy::cssSelector('[data-next-up] .next-card')) as $card) {
            if ($card->isDisplayed()) {
                $leads[] = $card->findElement(WebDriverBy::cssSelector('.next-lead'))->getText();
            }
        }
        self::assertGreaterThanOrEqual(1, count($leads));
        self::assertLessThanOrEqual(3, count($leads));
        foreach ($leads as $lead) {
            self::assertNotSame('', $lead, 'every card shown has its lead');
        }
        // 31 starts within the hour, 45 and 50 come next (korbo26's fixture participant)
        self::assertSame(['Za 30 min · 14:00', '11:00 · Nástěnka', '16:00'], $leads);
    }

    /**
     * A server toast waiting in the session, shown on the next Profil (Profil takes it).
     * The pairing start, a plain form POST, gives the browser its session first.
     */
    private static function toastOnProfil(string $slug): void
    {
        self::visit('/' . $slug . '/profil');
        self::follow('form[action="/' . $slug . '/profil/propojit"] button[type="submit"]');
        self::editSession($slug, static fn(array $bag): array => [Toasts::SESSION_KEY => ['title' => 'Zkušební toast', 'line' => null, 'at' => time()]] + $bag);

        self::visit('/' . $slug . '/profil');

        self::assertTrue(self::displayed('[data-toast]'), $slug . ': the toast is shown');
        self::assertStringContainsString('Zkušební toast', self::text('[data-toast]'), $slug);
        self::assertFalse(self::displayed('.toast-close'), $slug . ': no close button without a script');
    }

    /**
     * With reduced motion (the lane's default) a toast nothing can close still leaves: it
     * is hidden at once after six seconds (motion.css), so it never covers the screen for good.
     */
    public function testTheToastLeavesAfterSixSecondsWithReducedMotion(): void
    {
        foreach (self::EVENTS as $slug) {
            self::toastOnProfil($slug);

            usleep(7_000_000);

            self::assertFalse(self::displayed('[data-toast]'), $slug . ': the toast is gone');
        }
    }

    /** Where motion is allowed the toast fades out over --motion after the same six seconds. */
    public function testTheToastFadesAfterSixSeconds(): void
    {
        self::allowMotion();
        try {
            foreach (self::EVENTS as $slug) {
                self::toastOnProfil($slug);
                $toast = self::$browser->findElement(WebDriverBy::cssSelector('[data-toast]'));
                self::assertSame('toast-gone', $toast->getCSSValue('animation-name'), $slug);
                self::assertSame('6s', $toast->getCSSValue('animation-delay'), $slug);
                self::assertNotSame('0s', $toast->getCSSValue('animation-duration'), $slug . ': a fade, not the instant hide');

                usleep(7_000_000);

                self::assertFalse(self::displayed('[data-toast]'), $slug . ': the toast has faded');
            }
        } finally {
            self::resetMotion();
        }
    }
}
