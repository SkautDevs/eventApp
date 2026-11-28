<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * GET /informace/zpravy, the Zprávy view of Informace (Napiš organizátorům): the login card
 * for a reader who is not paired, the form for one who is, on the `inbox` fixture event.
 */
final class InboxPageTest extends AppTestCase
{
    public function testAnEventWithoutTheFeatureHasNoRoute(): void
    {
        $app = $this->createApp('emergency', fixtureEvent: true);

        self::assertSame(404, $this->request($app, 'GET', '/informace/zpravy')->getStatusCode());
    }

    /** The form's first address is gone: it was never deployed. */
    public function testTheOldAddressIsGone(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);
        $this->loginAs($app, 42, 'Kája');

        self::assertSame(404, $this->request($app, 'GET', '/zpravy')->getStatusCode());
    }

    public function testLoggedOutThePaneShowsTheLoginCardAndNoForm(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);

        $response = $this->request($app, 'GET', '/informace/zpravy');
        $html = (string) $response->getBody();
        $pane = $this->pane($html);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('~^\s*<h2>Napiš organizátorům</h2>\s*<p\b[^>]*>Napsat můžeš po přihlášení\.</p>\s*<section class="link-card is-highlight pair-card"~u', $pane);
        self::assertStringContainsString('<a class="btn btn-primary" href="/inbox/profil">Přihlásit se</a>', $pane);
        self::assertStringNotContainsString('<form', $this->screen($html));
        self::assertStringNotContainsString('nepiš a zavolej', $pane, 'the call buttons are for the form');
    }

    public function testLoggedInThePageShowsTheForm(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);
        $this->loginAs($app, 42, 'Kája');

        $response = $this->request($app, 'GET', '/informace/zpravy');
        $html = $this->pane((string) $response->getBody());
        $action = $app->getRouteCollector()->getRouteParser()->urlFor('inbox-send');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/inbox/informace/zpravy', $action);
        self::assertStringNotContainsString('Napsat můžeš po přihlášení.', $html);
        self::assertStringContainsString('Napiš organizátorům</h2>', $html);
        self::assertStringContainsString('Píšeš jako Kája.', $html);
        self::assertStringNotContainsString('pair-card', $html);

        self::assertSame(1, preg_match('/<form\b[^>]*>/', $html, $form));
        self::assertStringContainsString('method="post"', $form[0]);
        self::assertStringContainsString('action="' . $action . '"', $form[0]);
        self::assertMatchesRegularExpression('/\sdata-write[\s>]/', $form[0]);
        self::assertMatchesRegularExpression('/\sdata-inbox-form[\s>]/', $form[0]);

        self::assertSame(2, preg_match_all('/<input\b[^>]*type="radio"[^>]*>/', $html, $radios));
        foreach ($radios[0] as $radio) {
            self::assertStringContainsString('name="kind"', $radio);
            self::assertStringNotContainsString('checked', $radio);
        }
        self::assertStringContainsString('value="help"', $radios[0][0]);
        self::assertStringContainsString('aria-describedby="inbox-promise"', $radios[0][0]);
        self::assertStringContainsString('value="idea"', $radios[0][1]);
        // the promise line belongs to the help kind: the next thing after its label
        self::assertMatchesRegularExpression('~Potřebuju poradit nebo něco nefunguje</span>\s*</label>\s*<p\b[^>]*>Zprávy čteme ráno a večer\.</p>~u', $html);

        self::assertSame(1, preg_match('/<textarea name="body"[^>]*>/', $html, $textarea));
        self::assertMatchesRegularExpression('/\srequired[\s>]/', $textarea[0]);
        self::assertStringContainsString('maxlength="1000"', $textarea[0]);
        self::assertSame(1, preg_match('/<input type="text" name="contact"[^>]*>/', $html, $contact));
        self::assertStringContainsString('maxlength="100"', $contact[0]);
        self::assertStringNotContainsString('required', $contact[0]);

        foreach ([
            'Zprávu a kontakt uvidí organizátoři akce.',
            'O co jde?',
            'Potřebuju poradit nebo něco nefunguje',
            'Mám nápad nebo zpětnou vazbu',
            'Zpráva',
            'Telefon nebo e-mail, když chceš odpověď',
            'nepovinné',
            'Odeslat',
        ] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('role="alert"', $html, 'an empty form carries no errors');
    }

    public function testWithCrisisContactsTheFormOffersTheirCallButtons(): void
    {
        // the inbox fixture event has one contact, +420 000 000 000
        $app = $this->createApp('inbox', fixtureEvent: true);
        $this->loginAs($app, 42, 'Kája');

        $html = $this->pane((string) $this->request($app, 'GET', '/informace/zpravy')->getBody());

        // plain text, not a link to Informace: the numbers are right here
        self::assertMatchesRegularExpression('~<p\b[^>]*>Když jde o zdraví nebo bezpečí, nepiš a zavolej:</p>~u', $html);
        self::assertStringNotContainsString('volej krizový kontakt', $html);
        self::assertSame(1, preg_match_all('~<a class="btn btn-primary emergency-call" href="tel:([^"]*)">\s*Zavolat<span class="sr-only"> Krizová linka</span>~u', $html, $calls));
        self::assertSame(['+420000000000'], $calls[1], 'one call button per contact, its number without spaces');
        self::assertStringContainsString('<span class="emergency-number">+420 000 000 000</span>', $html);
        // where the line was: under the heading, above the sender and the form
        $at = (int) strpos($html, 'nepiš a zavolej:');
        self::assertGreaterThan((int) strpos($html, 'Napiš organizátorům</h2>'), $at);
        self::assertLessThan((int) strpos($html, 'emergency-call'), $at);
        self::assertLessThan((int) strpos($html, 'Píšeš jako'), (int) strpos($html, 'emergency-call'));
    }

    public function testWithoutCrisisContactsTheFormNamesNone(): void
    {
        // obrok19 enables the inbox without content/emergency.php; participant 1 is the one its
        // fixture knows, so the screen's participant read keeps them logged in
        $app = $this->createApp('obrok19');
        $this->loginAs($app, 1, 'Kája');

        $html = $this->screen((string) $this->request($app, 'GET', '/informace/zpravy')->getBody());
        $pane = $this->pane($html);

        self::assertStringContainsString('<form', $pane);
        self::assertStringNotContainsString('zdraví nebo bezpečí', $pane);
        // the Informace view's quick links may dial a number; the form has no call button
        self::assertStringNotContainsString('tel:', $pane);
        self::assertStringNotContainsString('emergency-list', $html);
        self::assertStringNotContainsString('inbox-urgent', $html);
    }

    public function testWithoutAPromiseTheHelpKindHasNoLineUnderIt(): void
    {
        // obrok19 enables the inbox without content/inbox.php (participant 1: its fixture's)
        $app = $this->createApp('obrok19');
        $this->loginAs($app, 1, 'Kája');

        $html = $this->pane((string) $this->request($app, 'GET', '/informace/zpravy')->getBody());

        self::assertStringContainsString('<form', $html);
        self::assertStringNotContainsString('inbox-promise', $html, 'no promise element, and no aria-describedby to one');
        self::assertMatchesRegularExpression('~Potřebuju poradit nebo něco nefunguje</span>\s*</label>\s*<label\b~u', $html);
    }

    public function testWithoutANicknameTheSenderIsTheParticipantNumber(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);
        $this->loginAs($app, 42, '  ');

        self::assertStringContainsString('Píšeš jako účastník č. 42.', (string) $this->request($app, 'GET', '/informace/zpravy')->getBody());
    }

    public function testThePageLightsTheInformaceTab(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);
        $info = $app->getRouteCollector()->getRouteParser()->urlFor('info');

        $html = (string) $this->request($app, 'GET', '/informace/zpravy')->getBody();

        self::assertMatchesRegularExpression('~<a class="tab is-active" href="' . preg_quote($info, '~') . '"[^>]* aria-current="page">~', $html);
        self::assertStringContainsString('data-tab="info"', $html);
    }

    public function testThePageAnswersAsAFragmentToo(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);

        $response = $this->request($app, 'GET', '/informace/zpravy', null, ['X-Screen' => '1']);
        $html = trim((string) $response->getBody());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('<section class="screen"', $html);
        self::assertStringNotContainsString('<!doctype', strtolower($html));
        self::assertStringContainsString('data-screen="/inbox/informace"', $html);
        self::assertStringContainsString('data-view="inbox"', $html);
        self::assertStringContainsString('Napiš organizátorům</h2>', $html);
    }

    /** www/js/inbox/ keeps the draft by these names, and forgets it on SendAction's landing. */
    public function testTheScriptAndTheTemplatesAgree(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/inbox/index.js');
        self::assertStringContainsString("'#odeslano'", $script);
        self::assertStringContainsString("'inboxDraft:'", $script);
        self::assertStringContainsString('data-inbox-form', $script);
        self::assertStringContainsString('[data-info-root]', $script);
        $views = (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/inbox/views.js');
        self::assertStringContainsString("'/zpravy'", $views);
        self::assertStringContainsString('data-info-view', $views);

        $app = $this->createApp('inbox', [\PDO::class => self::memoryDb()], fixtureEvent: true);
        $this->loginAs($app, 42, 'Kája');
        $response = $this->request($app, 'POST', '/informace/zpravy', ['kind' => 'idea', 'body' => 'Víc kafe', 'contact' => '']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/inbox/informace/zpravy#odeslano', $response->getHeaderLine('Location'));
    }

    /** The Zprávy pane of a page, from its opening tag to the pills after it. */
    private function pane(string $html): string
    {
        self::assertSame(1, preg_match('~<div class="info-pane" id="info-view-inbox" role="tabpanel" aria-labelledby="info-tab-inbox">(.*?)<div class="tabs-wrap"~s', $html, $pane), 'the page has the Zprávy pane');

        return $pane[1];
    }

    /** The screen's own section, without the shell around it. */
    private function screen(string $html): string
    {
        self::assertSame(1, preg_match('~<section class="screen".*</section>\s*</main>~s', $html, $screen), 'the page has a screen');

        return $screen[0];
    }
}
