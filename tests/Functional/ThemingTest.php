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

    /** Rules for markup that no longer exists are deleted, not kept "just in case". */
    public function testRetiredRulesAreGone(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        foreach (['border-table', 'harmonogram', 'login-hint', 'h3 + table', '.hide {'] as $retired) {
            self::assertStringNotContainsString($retired, $css, $retired);
        }
    }

    /** The bare-button reset is said once; each control states only what differs. */
    public function testTheButtonResetIsSaidOnce(): void
    {
        $css = (string) preg_replace('!/\*.*?\*/!s', '', (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css'));
        $buttons = ['.appbar-mode', '.pager-arrow', '.pager-label', '.pager-zoom-btn', '.pager-menu-item', '.pager-menu-close', '.tabs-tab', '.pl-open'];

        self::assertSame(1, preg_match('/(\.appbar-mode,[^{]*)\{([^}]*)\}/', $css, $reset));
        $members = array_map('trim', explode(',', (string) preg_replace('/\s+/', ' ', $reset[1])));
        sort($members);
        $expected = $buttons;
        sort($expected);
        self::assertSame($expected, $members);
        foreach (['padding: 0;', 'border: none;', 'background-color: transparent;', 'font-family: inherit;', 'cursor: pointer;'] as $declaration) {
            self::assertStringContainsString($declaration, $reset[2]);
        }

        foreach ($buttons as $button) {
            if (preg_match('/(?:^|\})\s*' . preg_quote($button, '/') . '\s*\{([^}]*)\}/', $css, $own) !== 1) {
                continue;
            }
            foreach (['border: none;', 'background-color: transparent;', 'font-family: inherit;', 'cursor: pointer;'] as $declaration) {
                self::assertStringNotContainsString($declaration, $own[1], $button . ' repeats the reset');
            }
        }
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

        // the page itself, not merely somewhere in the file — and the role it reads
        // resolves to the palette key for an event that declares no role of its own
        self::assertStringContainsString('background-color: var(--ground);', self::declarationsFor($css, 'body'));
        self::assertStringContainsString('--ground: var(--role-ground, var(--color-background));', $css);
    }

    public function testLinksAreThemedNotBrowserDefaultBlue(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        self::assertStringContainsString('color: var(--action-link);', self::declarationsFor($css, 'a'));
        self::assertStringContainsString('--action-link: var(--role-action-link, var(--color-link));', $css);
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

        self::assertStringContainsString('background-color: var(--ground);', self::declarationsFor($css, 'body'));
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
        self::assertIsInt($sheet, 'the stylesheet is not linked at all');
        self::assertLessThan($sheet, $script, 'the mode is set after the stylesheet is linked');
        self::assertStringNotContainsString('defer', substr($html, $script - 400, 400));
    }

    /**
     * An event with no dark set must not show a control that does nothing. Obrok 19
     * is that event: it declares a hand-authored LIGHT set like every other event —
     * it stopped being the frozen control when its bars went white — and no dark
     * one, so it gets the roles and none of the mode plumbing.
     */
    public function testTheToggleOnlyExistsForAnEventWithADarkPalette(): void
    {
        $dark = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();
        $light = (string) $this->request($this->createApp(), 'GET', '/')->getBody();

        self::assertStringContainsString('data-mode-toggle', $dark);
        self::assertStringContainsString('aria-label="Tmavý režim"', $dark);
        self::assertStringContainsString(':root[data-mode="dark"]', $dark);

        self::assertStringContainsString('--role-ground: #f7f7f5', $light);
        self::assertStringNotContainsString('data-mode-toggle', $light);
        self::assertStringNotContainsString('obrokColorMode', $light);
        self::assertStringNotContainsString('prefers-color-scheme', $light);
        self::assertStringNotContainsString('[data-mode="dark"]', $light);
        self::assertStringContainsString('color-scheme: light;', $light);
    }

    /**
     * Light mode is light and dark mode is dark, all the way through: the roles that
     * name a LARGE filled area — the page, its one block tier, and the chrome that
     * grounds every bar, sheet and card — have to be at the mode's own end of the
     * ramp. A near-black app bar in a light set is precisely the "black element in
     * the light mode" this round removed, and a mid-grey card in a dark one is the
     * same mistake upside down. The accents are deliberately not in this list: they
     * are small by construction, which is what lets them be vivid.
     */
    public function testTheLargeFilledAreasFollowTheMode(): void
    {
        $areas = ['ground', 'surface', 'structure', 'sheet', 'field', 'grid', 'grid-structure', 'stage'];
        $checked = 0;
        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            foreach (\App\EventConfig::load($this->eventsDir(), $slug)->roles as $mode => $set) {
                foreach ($areas as $name) {
                    if (!isset($set[$name]) || preg_match('/^#[0-9a-f]{6}$/i', (string) $set[$name]) !== 1) {
                        continue;
                    }
                    $checked++;
                    $luminance = self::luminance((string) $set[$name]);
                    if ($mode === 'light') {
                        self::assertGreaterThan(0.5, $luminance, sprintf('%s/light: "%s" (%s) is a dark fill in a light set', $slug, $name, $set[$name]));
                    } else {
                        self::assertLessThan(0.12, $luminance, sprintf('%s/dark: "%s" (%s) is a light fill in a dark set', $slug, $name, $set[$name]));
                    }
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no event declares a filled area to measure');
    }

    /**
     * The active tab is a filled chip in the event's colour with the glyph on top of
     * it, in both modes — the only shape an identity colour survives in light mode,
     * where obrok27's lime is 1.25:1 as ink on white. So the chip's ink owes AA on
     * the chip in every event that declares one.
     *
     * Measured against the active tab's OWN declarations. The two strings this used to
     * look for anywhere in the stylesheet are also in .appbar, which fills with the same
     * role — so the test went green straight through the round in which the tab carried
     * a fill with no ink of its own and the declarations that gave it one sat commented
     * out. Comments are stripped before the rules are read, for exactly that reason.
     */
    public function testTheActiveTabChipCarriesItsGlyphAtAA(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        $chip = self::declarationsFor($css, '.tab.is-active');

        self::assertNotSame('', $chip, 'the active tab has no rule of its own');
        self::assertMatchesRegularExpression(
            '/(?<![-a-z])background(-color)?:\s*var\(--state[,)]/',
            $chip,
            'the active tab is not filled with the event colour',
        );
        self::assertMatchesRegularExpression(
            '/(?<![-a-z])color:\s*var\(--on-state[,)]/',
            $chip,
            'the active tab carries the chip fill but not the ink that owes AA on it',
        );

        $checked = 0;
        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            foreach (\App\EventConfig::load($this->eventsDir(), $slug)->roles as $mode => $set) {
                self::assertArrayHasKey('state', $set, sprintf('%s/%s declares no active-tab chip', $slug, $mode));
                self::assertArrayHasKey('on-state', $set, sprintf('%s/%s: the chip has no ink', $slug, $mode));
                $checked++;
                self::assertGreaterThanOrEqual(
                    4.5,
                    self::contrast($set['state'], $set['on-state']),
                    sprintf('%s/%s: the active tab glyph fails AA on its chip', $slug, $mode),
                );
            }
        }

        self::assertSame(9, $checked, 'five events, nine role sets: obrok19 one, obrok27, korbo26, navigamus25 and miquik26 two each');
    }

    /** The sheet is a role now; the palette key that used to ground it is retired. */
    public function testTheSheetGroundIsARoleAndNotAPaletteKey(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        self::assertStringNotContainsString('--color-sheet-bg', $css);
        self::assertStringContainsString('--surface: var(--role-sheet, var(--color-base));', $css);

        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            self::assertArrayNotHasKey('sheet-bg', \App\EventConfig::load($this->eventsDir(), $slug)->colors, $slug);
        }
    }

    /**
     * The hour ruler is structure that carries the identity — a role of its own,
     * because `action` may not be painted on chrome and a monochrome grid leaves
     * the time axis nowhere to be found. It is the ONLY use of that role: a second
     * surface wearing it would put the screen back where round 8 found it.
     */
    public function testTheIdentityRoleIsUsedByTheHourRulerAndNothingElse(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');

        self::assertStringContainsString('--signature: var(--role-signature, var(--structure));', $css);
        // the ruler by name, not "some rule in the file" — the count below says how many
        // surfaces wear it, this says which one
        self::assertStringContainsString('background-color: var(--signature);', self::declarationsFor($css, '.tl-ruler'));
        // the role definition reads --role-signature, so every var(--signature) in
        // the file is a rule painting with it — and there is to be exactly one
        self::assertSame(
            1,
            substr_count($css, 'var(--signature)'),
            'the identity role is painted on more than the hour ruler',
        );
    }

    /**
     * A programme card is a card by a tone step AND a hairline, and neither carries
     * it alone. Round 9 asked the fill for 3:1 against the grid by itself, which is
     * the right shape of rule and the wrong threshold: on a near-black grid a 3:1
     * fill is a mid-grey block, i.e. a light element in a dark screen, and on a
     * light grid a white card can never reach it at all. So the fill owes a step
     * that is genuinely measurable rather than a nudge, and the hairline — which
     * round 9's rule said nothing about — owes 3:1 against BOTH sides of itself,
     * the card and the grid. A boundary that vanishes into either is not one.
     */
    public function testProgrammeCardsSeparateFromTheGridByToneAndHairline(): void
    {
        $checked = 0;
        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            foreach (\App\EventConfig::load($this->eventsDir(), $slug)->roles as $mode => $set) {
                foreach (['grid', 'grid-structure', 'hairline'] as $name) {
                    self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', (string) ($set[$name] ?? ''), sprintf('%s/%s: "%s" is not a measurable value', $slug, $mode, $name));
                }
                $checked++;

                self::assertGreaterThanOrEqual(
                    1.3,
                    self::contrast($set['grid-structure'], $set['grid']),
                    sprintf('%s/%s: the programme card sits on the grid with no tone step at all', $slug, $mode),
                );
                self::assertGreaterThanOrEqual(
                    3.0,
                    self::contrast($set['hairline'], $set['grid-structure']),
                    sprintf('%s/%s: the card hairline disappears into the card', $slug, $mode),
                );
                self::assertGreaterThanOrEqual(
                    3.0,
                    self::contrast($set['hairline'], $set['grid']),
                    sprintf('%s/%s: the card hairline disappears into the grid', $slug, $mode),
                );
            }
        }

        self::assertSame(9, $checked, 'five events, nine role sets: obrok19 one, obrok27, korbo26, navigamus25 and miquik26 two each');
    }

    /**
     * Structure is the one role that is a filled ground under inverted type at body
     * size, so the default is the palette's deeper tone: obrok19 carried its label
     * on --color-base at 3.85:1, below AA, on the secondary button, the link CTA
     * and the active tab alike.
     */
    public function testStructureDefaultsToThePalettesDeeperTone(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        self::assertStringContainsString('--structure: var(--role-structure, var(--color-darker));', $css);

        // Both shipped events declare their own structure pair now, so the fallback's
        // only remaining users are the fixture events — which is exactly why they are
        // kept on it, and why they are what this measures.
        $dirs = [$this->eventsDir(), dirname(__DIR__) . '/fixtures/events'];
        $checked = 0;
        foreach ($dirs as $dir) {
            foreach (glob($dir . '/*/config.php') ?: [] as $path) {
                $slug = basename(dirname($path));
                $event = \App\EventConfig::load($dir, $slug);
                if ($event->roles !== []) {
                    continue; // an event with roles states its own structure pair below
                }

                $checked++;
                self::assertGreaterThanOrEqual(
                    4.5,
                    self::contrast($event->colors['darker'], $event->colors['text-invert']),
                    sprintf('%s: the default structure ground fails AA under the inverted text colour', $slug),
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'nothing is left on the built-in fallback to measure');
    }

    /**
     * Every role pair an event hand-authors carries text, so every one of them owes
     * AA in both modes. Measured rather than asserted: a hex nudged by eye in a
     * config is exactly how a 4.4:1 pairing ships.
     */
    public function testEveryHandAuthoredRolePairClearsAA(): void
    {
        $checked = 0;
        $events = [];
        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            foreach (\App\EventConfig::load($this->eventsDir(), $slug)->roles as $mode => $set) {
                $events[$slug] = true;
                foreach ($set as $name => $value) {
                    $ink = $set['on-' . $name] ?? null;
                    if (!is_string($ink) || !preg_match('/^#[0-9a-f]{6}$/i', (string) $value) || !preg_match('/^#[0-9a-f]{6}$/i', $ink)) {
                        continue;
                    }
                    $checked++;
                    self::assertGreaterThanOrEqual(
                        4.5,
                        self::contrast((string) $value, $ink),
                        sprintf('%s/%s: "%s" (%s) and its ink (%s) fail AA', $slug, $mode, $name, $value, $ink),
                    );
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'no event declares a role pair to measure');
        // obrok19 hand-authors a set of its own now, so it is measured like any other
        self::assertSame(['korbo26', 'miquik26', 'navigamus25', 'obrok19', 'obrok27'], array_keys($events));
    }

    /**
     * Type is a seven-step rem scale, so a reader's own text size reaches it. Two kinds of
     * rule keep px, by name: chrome glyphs in the fixed-height bars, whose heights
     * www/programs.js reads as plain pixel numbers, and the timeline grid, whose geometry
     * is frozen. Anything else in px is a regression.
     */
    public function testTypeIsOnTheRemScaleOutsideTheInstrumentAllowlist(): void
    {
        $allowed = ['.appbar-profile', '.appbar-mode', '.tab i', '.pager-arrow', '.pager-arrow-day', '.pager-zoom-btn', '.tl-tick', '.tl-stage', '.tl-card'];
        $css = (string) preg_replace('!/\*.*?\*/!s', '', (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css'));

        $offenders = [];
        $sizes = 0;
        foreach (explode('}', $css) as $block) {
            $open = strrpos($block, '{');
            if ($open === false) {
                continue;
            }
            $selectors = substr($block, 0, $open);
            $wrapper = strrpos($selectors, '{');
            if ($wrapper !== false) {
                $selectors = substr($selectors, $wrapper + 1);
            }
            $selectors = trim((string) preg_replace('/\s+/', ' ', $selectors));
            if (preg_match_all('/(?<![-a-z])font-size:\s*([^;]+);/', substr($block, $open + 1), $values) === 0) {
                continue;
            }
            foreach ($values[1] as $value) {
                $sizes++;
                $value = trim($value);
                if (preg_match('/^var\(--fs-(?:2xs|xs|sm|md|lg|xl|2xl)\)$/', $value) === 1) {
                    continue;
                }
                if (in_array($selectors, $allowed, true) && preg_match('/^\d+px$/', $value) === 1) {
                    continue;
                }
                $offenders[] = $selectors . ' { font-size: ' . $value . ' }';
            }
        }

        self::assertGreaterThan(30, $sizes, 'the stylesheet lost its font sizes');
        self::assertSame([], $offenders, 'these sizes are neither on the scale nor allowlisted');
        // the shorthand must not carry a px size past the check above
        self::assertDoesNotMatchRegularExpression('/(?<![-a-z])font:\s*[^;]*\d+px/', $css);
        foreach (['2xs' => '0.6875rem', 'xs' => '0.8125rem', 'sm' => '0.875rem', 'md' => '1rem', 'lg' => '1.0625rem', 'xl' => '1.25rem', '2xl' => '1.5rem'] as $step => $value) {
            self::assertSame(1, substr_count($css, '--fs-' . $step . ': ' . $value . ';'), '--fs-' . $step);
        }
        self::assertStringContainsString('font-size: var(--fs-md);', self::declarationsFor($css, 'body'));

        // the two instance-level pages carry their own two values, in rem
        foreach (['picker.twig', 'instance-error.twig'] as $template) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
            self::assertDoesNotMatchRegularExpression('/font(?:-size)?:\s*[^;]*\d+px/', $source, $template);
        }
    }

    /**
     * One ring for every control, in the ground pair: ink on ground clears AA in every set,
     * and a halo in the ground separates it from any fill it lands on. The only rings of
     * their own are the three inset ones whose boxes clip, and the only rules without one
     * are the two dialog containers and the screen section, which take focus programmatically.
     */
    public function testTheFocusRingIsOneGlobalRule(): void
    {
        $css = (string) preg_replace('!/\*.*?\*/!s', '', (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css'));

        self::assertSame(1, preg_match_all('/(?:^|\})\s*:focus-visible\s*\{([^}]*)\}/', $css, $ring));
        self::assertStringContainsString('outline: 2px solid var(--on-ground);', $ring[1][0]);
        self::assertStringContainsString('outline-offset: 2px;', $ring[1][0]);
        self::assertStringContainsString('box-shadow: 0 0 0 4px var(--ground);', $ring[1][0]);

        preg_match_all('/([^{}]+)\{[^}]*outline:\s*none/', $css, $none);
        $selectors = array_map(static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s)), $none[1]);
        sort($selectors);
        $exempt = ['.pager-menu-card:focus', '.screen:focus-visible', '.sheet-card:focus'];
        self::assertSame($exempt, $selectors);
        foreach ($exempt as $container) {
            self::assertStringContainsString('box-shadow: none;', self::declarationsFor($css, $container), $container . ' still draws the halo');
        }

        // the three inset rings take the global ink; they only move it inside the box
        foreach (['.tabs-tab:focus-visible', '.tl-card:focus-visible', '.sheet-close:focus-visible'] as $inset) {
            $rule = self::declarationsFor($css, $inset);
            self::assertStringNotContainsString('var(--on-structure)', $rule, $inset);
            self::assertMatchesRegularExpression('/box-shadow: inset 0 0 0 \d+px var\(--ground\);/', $rule, $inset);
        }

        // the ring never animates
        $motion = strpos($css, '@media (prefers-reduced-motion: no-preference)');
        self::assertIsInt($motion);
        self::assertDoesNotMatchRegularExpression('/outline|box-shadow/', substr($css, $motion));
    }

    /**
     * The loading bar is 2px on the tab bar's top edge, so it has to read against that bar.
     * `state` did not: obrok27's lime on its pale light-mode bar is 1.39:1. The bar's own
     * ink is held to the non-text minimum in every set that declares one.
     */
    public function testTheLoadingBarClearsThreeToOneOnTheTabBar(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        self::assertMatchesRegularExpression('/(?<![-a-z])background(?:-color)?:\s*var\(--on-structure\);/', self::declarationsFor($css, '.progress'));
        self::assertStringContainsString('background-color: var(--structure);', self::declarationsFor($css, '.tabbar'));

        $checked = 0;
        foreach (glob($this->eventsDir() . '/*/config.php') ?: [] as $path) {
            $slug = basename(dirname($path));
            foreach (\App\EventConfig::load($this->eventsDir(), $slug)->roles as $mode => $set) {
                self::assertArrayHasKey('structure', $set, $slug . '/' . $mode);
                self::assertArrayHasKey('on-structure', $set, $slug . '/' . $mode);
                $checked++;
                self::assertGreaterThanOrEqual(3.0, self::contrast($set['on-structure'], $set['structure']), sprintf('%s/%s: the loading bar is under 3:1 on the tab bar', $slug, $mode));
            }
        }
        self::assertSame(9, $checked);
    }

    /**
     * Every declaration of every rule whose selector list names $selector — the rule
     * itself, its pseudo-class variants and its descendants — with comments stripped.
     *
     * A theming guarantee is about a component, and a substring search over the whole
     * stylesheet answers for any component at all, including a commented-out one. This
     * is a reader, not a parser: it splits on braces, which is enough for a hand-written
     * flat stylesheet and unwraps one level of at-rule around a rule.
     */
    private static function declarationsFor(string $css, string $selector): string
    {
        $css = (string) preg_replace('!/\*.*?\*/!s', '', $css);
        $found = '';
        foreach (explode('}', $css) as $block) {
            $open = strrpos($block, '{');
            if ($open === false) {
                continue;
            }

            $selectors = substr($block, 0, $open);
            $wrapper = strrpos($selectors, '{'); // an @media opener before the rule's own list
            if ($wrapper !== false) {
                $selectors = substr($selectors, $wrapper + 1);
            }
            if (self::selectorListNames($selectors, $selector)) {
                $found .= substr($block, $open + 1) . "\n";
            }
        }

        return $found;
    }

    /** True when one of the comma-separated selectors is $selector, or is scoped by it. */
    private static function selectorListNames(string $selectors, string $selector): bool
    {
        foreach (explode(',', $selectors) as $one) {
            $one = trim((string) preg_replace('/\s+/', ' ', $one));
            if ($one === $selector || str_starts_with($one, $selector . ' ') || str_starts_with($one, $selector . ':')) {
                return true;
            }
        }

        return false;
    }

    /** WCAG 2.1 relative-luminance contrast ratio between two #rrggbb values. */
    private static function contrast(string $a, string $b): float
    {
        $one = self::luminance($a);
        $two = self::luminance($b);

        return (max($one, $two) + 0.05) / (min($one, $two) + 0.05);
    }

    /** WCAG 2.1 relative luminance of a #rrggbb value: 0 is black, 1 is white. */
    private static function luminance(string $hex): float
    {
        $rgb = sscanf(ltrim($hex, '#'), '%2x%2x%2x') ?? [0, 0, 0];
        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((int) $rgb[0]) + 0.7152 * $channel((int) $rgb[1]) + 0.0722 * $channel((int) $rgb[2]);
    }

    /** The bar is built from the event's features, not from a hardcoded list of five. */
    public function testTabBarOnlyShowsEnabledFeatures(): void
    {
        $html = (string) $this->request($this->createApp('minimal', fixtureEvent: true), 'GET', '/novinky')->getBody();

        self::assertStringContainsString('Novinky', $html);
        self::assertStringNotContainsString('Odkazy', $html);
        self::assertStringNotContainsString('Mapa', $html);
    }

    public function testNoInlineHandlersInTemplates(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/templates/*.twig') ?: [] as $template) {
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', (string) file_get_contents($template), basename($template));
        }
    }

    /** The head script has to be inline (it runs before the stylesheet); the tap handler does not. */
    public function testADarkEventRendersOneInlineScript(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertSame(1, preg_match_all('~<script\b(?![^>]*\bsrc=)[^>]*>~i', $html));
    }

    /** APG toggle: a constant name, and aria-pressed carries the state. */
    public function testTheToggleLabelIsConstant(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();
        $app = (string) file_get_contents(dirname(__DIR__, 2) . '/www/app.js');
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.twig');

        self::assertStringContainsString('data-mode-toggle aria-pressed="false" aria-label="Tmavý režim"', $html);
        foreach (['app.js' => $app, '_layout.twig' => $layout] as $name => $source) {
            self::assertStringNotContainsString('Přepnout', $source, $name);
        }
        self::assertStringNotContainsString("setAttribute('aria-label'", $app);
        self::assertStringContainsString("setAttribute('aria-pressed'", $app);
    }

    /**
     * The loader's IIFE returns early on a page without a screen or without Map; the
     * toggle must not share that fate, so it is its own IIFE and comes first.
     */
    public function testTheToggleIsWiredOutsideTheLoader(): void
    {
        $app = (string) file_get_contents(dirname(__DIR__, 2) . '/www/app.js');
        $toggle = strpos($app, "document.querySelector('[data-mode-toggle]')");
        $loader = strpos($app, "var main = document.querySelector('main');");

        self::assertIsInt($toggle);
        self::assertIsInt($loader);
        self::assertLessThan($loader, $toggle);
        // the toggle's IIFE is closed before the loader's opens (app.js already holds
        // nested `(function () {` openers, so counting those would prove nothing)
        self::assertSame(1, substr_count(substr($app, $toggle, $loader - $toggle), '})();'), 'the toggle must close its own IIFE before the loader opens');
    }
}
