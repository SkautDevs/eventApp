<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * GET /zpravy, Napiš organizátorům: the login card for a reader who is not paired, the form
 * for one who is, on the `inbox` fixture event.
 */
final class InboxPageTest extends AppTestCase
{
    public function testAnEventWithoutTheFeatureHasNoRoute(): void
    {
        $app = $this->createApp('emergency', fixtureEvent: true);

        self::assertSame(404, $this->request($app, 'GET', '/zpravy')->getStatusCode());
    }

    public function testLoggedOutThePageShowsTheLoginCardAndNoForm(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);

        $response = $this->request($app, 'GET', '/zpravy');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Napiš organizátorům</h2>', $html);
        self::assertStringContainsString('class="link-card is-highlight pair-card"', $html);
        self::assertStringNotContainsString('<form', $this->screen($html));
    }

    public function testLoggedInThePageShowsTheForm(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);
        $this->loginAs($app, 42, 'Kája');

        $response = $this->request($app, 'GET', '/zpravy');
        $html = $this->screen((string) $response->getBody());
        $action = $app->getRouteCollector()->getRouteParser()->urlFor('inbox');

        self::assertSame(200, $response->getStatusCode());
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
            'Když jde o zdraví nebo bezpečí, nepiš a volej krizový kontakt.',
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

    public function testWithoutANicknameTheSenderIsTheParticipantNumber(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);
        $this->loginAs($app, 42, '  ');

        self::assertStringContainsString('Píšeš jako účastník č. 42.', (string) $this->request($app, 'GET', '/zpravy')->getBody());
    }

    public function testThePageLightsTheInformaceTab(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);
        $info = $app->getRouteCollector()->getRouteParser()->urlFor('info');

        $html = (string) $this->request($app, 'GET', '/zpravy')->getBody();

        self::assertMatchesRegularExpression('~<a class="tab is-active" href="' . preg_quote($info, '~') . '"[^>]* aria-current="page">~', $html);
        self::assertStringContainsString('data-tab="info"', $html);
    }

    public function testThePageAnswersAsAFragmentToo(): void
    {
        $app = $this->createApp('inbox', fixtureEvent: true);

        $response = $this->request($app, 'GET', '/zpravy', null, ['X-Screen' => '1']);
        $html = trim((string) $response->getBody());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('<section class="screen"', $html);
        self::assertStringNotContainsString('<!doctype', strtolower($html));
        self::assertStringContainsString('Napiš organizátorům</h2>', $html);
    }

    /** www/js/inbox/ keeps the draft by these names, and forgets it on SendAction's landing. */
    public function testTheScriptAndTheTemplatesAgree(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/inbox/index.js');
        self::assertStringContainsString("'#odeslano'", $script);
        self::assertStringContainsString("'inboxDraft:'", $script);
        self::assertStringContainsString('data-inbox-form', $script);

        $app = $this->createApp('inbox', [\PDO::class => self::memoryDb()], fixtureEvent: true);
        $this->loginAs($app, 42, 'Kája');
        $response = $this->request($app, 'POST', '/zpravy', ['kind' => 'idea', 'body' => 'Víc kafe', 'contact' => '']);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('#odeslano', $response->getHeaderLine('Location'));
    }

    /** The screen's own section, without the shell around it. */
    private function screen(string $html): string
    {
        self::assertSame(1, preg_match('~<section class="screen".*</section>\s*</main>~s', $html, $screen), 'the page has a screen');

        return $screen[0];
    }
}
