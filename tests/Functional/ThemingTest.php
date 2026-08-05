<?php

declare(strict_types=1);

namespace Tests\Functional;

final class ThemingTest extends AppTestCase
{
    public function testPaletteIsInjectedFromEventConfig(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/')->getBody();

        self::assertStringContainsString('--color-base: #2a9272', $html);
        self::assertStringContainsString('--color-primary: #96201f', $html);
    }

    public function testFaviconsPointToEventDir(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/')->getBody();

        self::assertStringContainsString('events/obrok19/favicon-32x32.png', $html);
        self::assertStringContainsString('events/obrok19/site.webmanifest', $html);
    }

    public function testCoreStylesheetHasNoColorLiterals(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        // dropping the :root block must never be necessary — the palette is injected by the layout.
        // (?!-) keeps the property name "white-space" from reading as the colour keyword.
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}\b|rgba?\(|\b(?:white|black)\b(?!-)/i', $css);
    }

    public function testPageBackgroundComesFromPalette(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        self::assertStringContainsString('var(--color-background)', $css);
    }

    public function testLinksAreThemedNotBrowserDefaultBlue(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        self::assertStringContainsString('var(--color-link)', $css);
    }

    public function testAppBarCarriesThePageTitle(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/novinky')->getBody();

        self::assertStringContainsString('<span class="appbar-title">Novinky</span>', $html);
        self::assertStringContainsString('Novinky · Obrok 2019', $html);
    }

    /** An event that declares a theme gets it next to the palette, as --theme-* properties. */
    public function testThemeMapIsEmittedAsCustomProperties(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/programy')->getBody();

        self::assertStringContainsString('--theme-radius: 0', $html);
        self::assertStringContainsString("--theme-font: 'Montserrat', sans-serif", $html);
        // colour left the theme map for the role layer, which has to exist twice
        self::assertStringNotContainsString('--theme-stage-bg', $html);
        self::assertStringContainsString('--role-structure:', $html);
    }

    /**
     * Every shade this design needs is mixed from the palette that already exists,
     * so correcting an event's identity colour moves its whole ramp with it. A hex
     * in a theme value would be a second source of truth for the same colour.
     */
    public function testDerivedShadesCarryNoHexOfTheirOwn(): void
    {
        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            foreach (\App\EventConfig::load($this->eventsDir(), $slug)->theme as $name => $value) {
                self::assertDoesNotMatchRegularExpression(
                    '/#[0-9a-fA-F]{3,6}\b|rgba?\(|\b(?:white|black)\b/i',
                    (string) $value,
                    sprintf('%s theme key "%s" names a colour instead of deriving one', $slug, $name),
                );
            }
        }
    }

    /**
     * The page ground is its own token: --color-background stays the identity
     * colour the accents are mixed from, while the largest surface in the app
     * takes a pale tint of it so the accents can still read as accents.
     */
    public function testPageGroundIsSeparateFromTheIdentityColour(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('background-color: var(--ground);', $css);
        self::assertStringContainsString('--ground: var(--role-ground, var(--color-background));', $css);
        // a near-white carrying only a trace of the hue, not the identity colour itself
        self::assertStringContainsString('--role-ground: #f6f7f3', $html);
        // the identity value is untouched — it is what the accents are made of
        self::assertStringContainsString('--color-background: #c2ea3a', $html);
    }

    /** The coral belonged to no palette and to no job, so it is gone from both events. */
    public function testTheCoralCloseRowIsGoneFromEveryEvent(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        // no dangling fallback plumbing for a key no event carries any more
        self::assertStringNotContainsString('--color-sheet-close', $css);

        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            self::assertArrayNotHasKey('sheet-close', \App\EventConfig::load($this->eventsDir(), $slug)->colors, $slug);
        }
    }

    /**
     * Theme values are raw CSS authored in the event config, so a family stack keeps
     * its apostrophes instead of arriving as &#039; and breaking the declaration.
     */
    public function testThemeValuesReachTheStyleBlockUnescaped(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString("--theme-font: 'Montserrat', sans-serif", $html);
        self::assertStringNotContainsString('&#039;Montserrat&#039;', $html);
    }

    /** font-url is a document resource, not a property: it is linked, not declared. */
    public function testFontUrlBecomesAStylesheetLinkAndNotAProperty(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat', $html);
        self::assertStringNotContainsString('--theme-font-url', $html);
    }

    /** The whole point of the map being optional: no theme, no properties, still boots. */
    public function testEventWithoutAThemeEmitsNoneAndStillBoots(): void
    {
        $response = $this->request($this->createApp('minimal', fixtureEvent: true), 'GET', '/novinky');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('--theme-', $html);
        self::assertStringNotContainsString('fonts.googleapis.com', $html);
        // the palette is still injected, so the page is themed as it always was
        self::assertStringContainsString('--color-base: #000000', $html);
    }

    /** Obrok 19 declares no theme at all — it is the control for every fallback below. */
    public function testReferenceEventDeclaresNoTheme(): void
    {
        $html = (string) $this->request($this->createApp(), 'GET', '/programy')->getBody();

        self::assertStringNotContainsString('--theme-', $html);
    }

    /**
     * An event that declares nothing must render exactly as it did before the theme
     * map existed, which only holds if every --theme-* the stylesheet reads carries
     * its previous value as the var() fallback.
     */
    public function testEveryThemeTokenHasAFallbackInTheStylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        preg_match_all('/var\(\s*(--theme-[a-z0-9-]+)\s*([,)])/i', $css, $matches, PREG_SET_ORDER);

        self::assertNotEmpty($matches, 'the stylesheet reads no theme token at all');
        foreach ($matches as [$whole, $token, $next]) {
            self::assertSame(',', $next, sprintf('%s is read without a fallback: %s', $token, $whole));
        }
    }

    /** A typo in an event config would otherwise be a token nothing ever reads. */
    public function testEveryTokenDeclaredByAnEventIsConsumedByTheStylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            foreach (\App\EventConfig::load($this->eventsDir(), $slug)->theme as $name => $value) {
                if ($name === 'font-url') {
                    continue; // linked by the layout rather than read by the stylesheet
                }
                self::assertStringContainsString('var(--theme-' . $name . ',', $css, sprintf('%s declares an unused theme key "%s"', $slug, $name));
            }
        }
    }

    /**
     * The rule that fixes "the colours are really mixed": no rule in the stylesheet
     * names a palette key any more. What is left of --color- is the role definitions
     * themselves, which are the one place the palette is allowed to be read.
     */
    public function testNoRuleInTheStylesheetNamesAPaletteKey(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        $offenders = [];
        foreach (explode("\n", $css) as $i => $line) {
            if (!str_contains($line, 'var(--color-')) {
                continue;
            }
            // a role definition is `--name: var(--role-x, <palette expression>)`
            if (preg_match('/^\s*--[a-z0-9-]+:\s*var\(--role-[a-z0-9-]+,/', $line) === 1) {
                continue;
            }
            $offenders[] = ($i + 1) . ': ' . trim($line);
        }

        self::assertSame([], $offenders, 'these rules read the palette instead of a role');
    }

    /** Every role has to resolve for an event that declares none, or obrok19 breaks. */
    public function testEveryRoleFallsBackToThePalette(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        preg_match_all('/var\(\s*(--role-[a-z0-9-]+)\s*([,)])/i', $css, $matches, PREG_SET_ORDER);

        self::assertNotEmpty($matches, 'the stylesheet reads no role at all');
        foreach ($matches as [$whole, $role, $next]) {
            self::assertSame(',', $next, sprintf('%s is read without a fallback: %s', $role, $whole));
        }
    }

    /** A role an event declares that nothing reads is a typo, not a feature. */
    public function testEveryRoleAnEventDeclaresIsConsumed(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            foreach (\App\EventConfig::load($this->eventsDir(), $slug)->roles as $mode => $set) {
                foreach (array_keys($set) as $name) {
                    self::assertStringContainsString('var(--role-' . $name . ',', $css, sprintf('%s/%s declares an unread role "%s"', $slug, $mode, $name));
                }
            }
        }
    }

    /** Light and dark are two hand-authored sets of the same roles, not a derivation. */
    public function testLightAndDarkDeclareTheSameRoles(): void
    {
        $roles = \App\EventConfig::load($this->eventsDir(), 'obrok27')->roles;

        self::assertNotEmpty($roles['light']);
        self::assertSame(array_keys($roles['light']), array_keys($roles['dark']));
        // and they are genuinely different values, not the same set twice
        self::assertNotSame($roles['light']['ground'], $roles['dark']['ground']);
        self::assertNotSame($roles['light']['action'], $roles['dark']['action']);
    }

    /**
     * The mode has to be on the root element before the stylesheet is applied, or the
     * reader sees a frame of the wrong theme. A deferred script at the foot of the
     * page cannot do that, so this asserts the ordering rather than the behaviour.
     */
    public function testTheModeIsSetBeforeTheStylesheetIsLinked(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        $script = strpos($html, "localStorage.getItem('obrokColorMode')");
        $sheet = strpos($html, 'href="style.css');
        self::assertIsInt($script, 'the mode script is missing');
        self::assertLessThan($sheet, $script, 'the mode is set after the stylesheet is linked');
        self::assertStringNotContainsString('defer', substr($html, $script - 400, 400));
    }

    /** An event with no dark set must not show a control that does nothing. */
    public function testTheToggleOnlyExistsForAnEventWithADarkPalette(): void
    {
        $dark = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();
        $light = (string) $this->request($this->createApp(), 'GET', '/')->getBody();

        self::assertStringContainsString('data-mode-toggle', $dark);
        self::assertStringContainsString('aria-label="Přepnout světlý a tmavý režim"', $dark);
        self::assertStringContainsString(':root[data-mode="dark"]', $dark);

        self::assertStringNotContainsString('data-mode-toggle', $light);
        self::assertStringNotContainsString('obrokColorMode', $light);
        self::assertStringNotContainsString('--role-', $light);
        self::assertStringContainsString('color-scheme: light;', $light);
    }

    /** The bar is built from the event's features, not from a hardcoded list of five. */
    public function testTabBarOnlyShowsEnabledFeatures(): void
    {
        $html = (string) $this->request($this->createApp('minimal', fixtureEvent: true), 'GET', '/novinky')->getBody();

        self::assertStringContainsString('Novinky', $html);
        self::assertStringNotContainsString('Odkazy', $html);
        self::assertStringNotContainsString('Mapa', $html);
    }
}
