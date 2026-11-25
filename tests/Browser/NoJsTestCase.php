<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\Exception\StaleElementReferenceException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * The app in a Chrome with JavaScript switched off: the reader the site has to serve in
 * full with links and forms alone. Scripts are turned off over CDP
 * (`Emulation.setScriptExecutionDisabled`), a setting of the tab that outlives every
 * navigation in it, like the viewport override; each class gets a Chrome session of its
 * own, so no service worker from another class answers either.
 *
 * The helpers drive the page through WebDriver's own commands only (find, attribute,
 * displayed, text, click), never through executeScript, which is the page's JavaScript.
 * A subclass carries `#[Group('browser')]` itself: PHPUnit does not inherit it.
 */
#[Group('browser')]
abstract class NoJsTestCase extends BrowserTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::devTools()->execute('Emulation.setScriptExecutionDisabled', ['value' => true]);
    }

    /** Every subclass runs it: no script ran, so the head script never marked the root. */
    public function testScriptsAreOff(): void
    {
        self::visit('/obrok19/');

        $class = (string) self::$browser->findElement(WebDriverBy::cssSelector('html'))->getAttribute('class');

        self::assertNotContains('js', preg_split('/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** The current URL's path plus its hash: `/obrok19/programy#den-…`. */
    protected static function path(): string
    {
        $url = parse_url(self::$browser->getCurrentURL());
        self::assertIsArray($url);
        $path = (string) ($url['path'] ?? '/');
        if (isset($url['query'])) {
            $path .= '?' . $url['query'];
        }

        return $path . (isset($url['fragment']) ? '#' . $url['fragment'] : '');
    }

    /** Whether the first element matching $css is displayed; one that is missing fails the test. */
    protected static function displayed(string $css): bool
    {
        return self::element($css)->isDisplayed();
    }

    /** The visible text of the first element matching $css; one that is missing fails the test. */
    protected static function text(string $css): string
    {
        return self::element($css)->getText();
    }

    /** An attribute of the first element matching $css, null when it has none; one that is missing fails the test. */
    protected static function attribute(string $css, string $name): ?string
    {
        return self::element($css)->getAttribute($name);
    }

    /**
     * A real click on the first element matching $css, then waits for the page it loads:
     * the old document going stale, after which WebDriver itself holds every command until
     * the new one has loaded. A click that only changes the hash keeps the document, so
     * the wait also ends when the URL changes.
     */
    protected static function follow(string $css): void
    {
        $before = self::$browser->getCurrentURL();
        $root = self::$browser->findElement(WebDriverBy::cssSelector('html'));
        self::element($css)->click();
        self::$browser->wait(10, 100)->until(
            static function () use ($root, $before): bool {
                try {
                    $root->getTagName();
                } catch (StaleElementReferenceException) {
                    return true;
                }

                return self::$browser->getCurrentURL() !== $before;
            },
            'Timed out waiting for the page after a click on ' . $css,
        );
        // a WebDriver command that waits for the new document to finish loading
        self::$browser->findElement(WebDriverBy::cssSelector('html'));
    }

    /**
     * Pairs the browser without a script, as such a reader does: Profil, `Přihlásit kódem
     * z KissJ` (or `Začít znovu` over a pairing an earlier test left pending, as pair()
     * does), then `Zkontrolovat přihlášení` until Profil shows who is paired; the local
     * source approves at once. A browser already paired is left as it is.
     */
    protected static function pairWithoutScript(string $slug): void
    {
        self::visit('/' . $slug . '/profil');
        if (self::present('.profile-who')) {
            return;
        }
        self::follow('form[action="/' . $slug . '/profil/propojit"] button[type="submit"]');
        for ($check = 0; $check < 3 && !self::present('.profile-who'); $check++) {
            self::follow('form[action="/' . $slug . '/profil/kontrola"] button[type="submit"]');
        }

        self::assertTrue(self::displayed('.profile-who'), $slug . ': paired through the check button');
    }

    /** Whether anything matches $css on the page. */
    protected static function present(string $css): bool
    {
        return self::$browser->findElements(WebDriverBy::cssSelector($css)) !== [];
    }

    /**
     * Lets the page see `prefers-reduced-motion: no-preference`, over the lane's
     * --force-prefers-reduced-motion. For the rest of the class's tab; resetMotion() puts it back.
     */
    protected static function allowMotion(): void
    {
        self::devTools()->execute('Emulation.setEmulatedMedia', [
            'features' => [['name' => 'prefers-reduced-motion', 'value' => 'no-preference']],
        ]);
    }

    /** Drops allowMotion()'s override: the lane's reduced motion again. */
    protected static function resetMotion(): void
    {
        self::devTools()->execute('Emulation.setEmulatedMedia', ['features' => []]);
    }

    private static function element(string $css): WebDriverElement
    {
        $found = self::$browser->findElements(WebDriverBy::cssSelector($css));
        self::assertNotEmpty($found, 'Nothing matches ' . $css . ' on ' . self::path());

        return $found[0];
    }
}
