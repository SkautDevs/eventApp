<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

/**
 * Napiš organizátorům without JavaScript, on obrok19: the `Zprávy` pill on Informace is a
 * link to the Zprávy view (`/informace/zpravy`, with Domů's login card when logged out),
 * the form is a plain POST that lands on the Zprávy view with the toast, on obrok27 the
 * Zprávy view's call button is a plain tel: link, and on the admin page a message is
 * deleted in two steps, the `Smazat…` summary first, with no script and no browser dialog.
 * The admin link opens with this class's own ADMIN_TOKEN_OBROK19.
 */
#[Group('browser')]
final class NoJsInboxTest extends NoJsTestCase
{
    private const string SLUG = 'obrok19';

    /** The Zprávy view of Informace. */
    private const string FORM = '/obrok19/informace/zpravy';

    private const string ADMIN_TOKEN = 'nojs-inbox-admin-token';

    /** @return array<string, string> */
    protected static function serverEnvironment(): array
    {
        return ['ADMIN_TOKEN_OBROK19' => self::ADMIN_TOKEN] + parent::serverEnvironment();
    }

    public function testAPairedReaderSendsAMessageFromThePillToTheToast(): void
    {
        self::pairWithoutScript(self::SLUG);
        self::visit('/' . self::SLUG . '/informace');
        self::assertTrue(self::displayed('#info-view-info'), 'Informace opens on its own view');
        self::assertFalse(self::displayed('form[data-inbox-form]'));

        self::follow('#info-tab-inbox');

        self::assertSame(self::FORM, self::path(), 'the pill leads to the Zprávy view');
        self::assertTrue(self::displayed('form[data-inbox-form]'), 'the form is on show');
        self::assertFalse(self::displayed('#info-view-info'));
        self::assertSame('page', self::attribute('#info-tab-inbox', 'aria-current'));
        self::assertFalse(self::present('[data-toast]'));
        self::fillAndSend('help', 'Neteče voda ve sprchách u brány.');

        // without a script nothing takes the landing's hash away
        self::assertSame(self::FORM . '#odeslano', self::path(), 'the send lands on the Zprávy view');
        self::assertTrue(self::displayed('form[data-inbox-form]'), 'with the form on show');
        self::assertSame('', self::attribute('#inbox-body', 'value'), 'empty again');
        self::assertTrue(self::displayed('[data-toast]'), 'the toast is shown');
        self::assertSame('Zpráva odeslána, díky!', self::text('[data-toast] strong'));
        // obrok19 has no promise line, so even the help kind's toast has no second line
        self::assertFalse(self::present('[data-toast] span'));
    }

    /** obrok27 has a crisis contact: the Zprávy view dials it, a plain tel: link; obrok19 has none. */
    public function testTheZpravyViewCallsTheCrisisNumberWhereTheEventHasOne(): void
    {
        self::pairWithoutScript('obrok27');
        self::visit('/obrok27/informace/zpravy');

        self::assertSame('Když jde o zdraví nebo bezpečí, nepiš a zavolej:', self::text('.inbox-urgent-title'));
        self::assertTrue(self::displayed('.inbox-urgent a.emergency-call'), 'the call button is shown');
        self::assertSame('tel:+420704444015', self::attribute('.inbox-urgent a.emergency-call', 'href'));

        self::pairWithoutScript(self::SLUG);
        self::visit(self::FORM);

        self::assertTrue(self::present('form[data-inbox-form]'));
        self::assertFalse(self::present('.inbox-urgent'), 'no contact, no line pointing at one');
    }

    public function testWithoutALoginThePillLeadsToTheLoginCard(): void
    {
        self::visit('/' . self::SLUG . '/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/' . self::SLUG . '/informace');

        self::follow('#info-tab-inbox');

        self::assertSame(self::FORM, self::path(), 'logged out, the pill leads to the Zprávy view too');
        self::assertSame('Napsat můžeš po přihlášení.', self::text('.inbox-login'));
        self::assertFalse(self::present('form[data-inbox-form]'), 'no form without a login');
        self::assertSame('Přihlásit se', self::text('#info-view-inbox .pair-card .btn'));
        self::follow('#info-view-inbox a[href="/' . self::SLUG . '/profil"]');

        self::assertSame('/' . self::SLUG . '/profil', self::path(), 'the login card leads to the login');
        self::assertTrue(self::present('form[action="/' . self::SLUG . '/profil/propojit"]'), 'Profil offers the login');
    }

    public function testAnEmptyTextIsStoppedOrAnsweredWithTheError(): void
    {
        self::pairWithoutScript(self::SLUG);
        self::visit(self::FORM);
        self::$browser->findElement(WebDriverBy::cssSelector('input[name="kind"][value="idea"]'))->click();

        // the textarea is `required`, so the browser itself may refuse to send the form
        self::$browser->findElement(WebDriverBy::cssSelector('form[data-inbox-form] button[type="submit"]'))->click();
        self::$browser->findElement(WebDriverBy::cssSelector('html'));

        self::assertSame(self::FORM, self::path(), 'still on the Zprávy view');
        self::assertTrue(self::displayed('form[data-inbox-form]'));
        if (self::present('.notice[role="alert"]')) {
            self::assertSame('Napiš zprávu.', self::text('.notice[role="alert"]'));
        } else {
            self::assertTrue(self::present('form[data-inbox-form]'), 'the browser kept the form');
        }
        self::assertFalse(self::present('[data-toast]'), 'nothing was sent');
    }

    public function testAnOrganiserDeletesInTwoSteps(): void
    {
        $text = 'Smazat po vyřízení ' . bin2hex(random_bytes(4));
        self::pairWithoutScript(self::SLUG);
        self::visit(self::FORM);
        // the help kind, newest first: the first message of the section
        self::fillAndSend('help', $text);
        self::assertSame(self::FORM . '#odeslano', self::path());

        self::visit('/' . self::SLUG . '/admin/notify?token=' . self::ADMIN_TOKEN);
        self::assertSame('/' . self::SLUG . '/admin/notify', self::path(), 'the link logs the organiser in');
        // the section at the top of the window, as the page's own link to it puts it, so the
        // summary is clear of the tab bar docked at the bottom
        self::visit('/' . self::SLUG . '/admin/notify#inbox');
        self::assertStringContainsString($text, self::text('#inbox'), 'the message is listed');
        $article = self::$browser->findElement(WebDriverBy::xpath('//article[contains(@class, "inbox-message")][.//p[contains(@class, "inbox-body")][. = "' . $text . '"]]'));
        // WebDriver gives the resolved URL: its path is the form's own selector
        $action = (string) parse_url((string) $article->findElement(WebDriverBy::cssSelector('.inbox-delete form'))->getAttribute('action'), PHP_URL_PATH);
        self::assertMatchesRegularExpression('~^/obrok19/admin/inbox/\d+/delete$~', $action);
        $confirm = 'form[action="' . $action . '"] button[type="submit"]';
        self::assertFalse(self::displayed($confirm), 'one tap deletes nothing: the button waits behind the summary');

        $article->findElement(WebDriverBy::cssSelector('.inbox-delete summary'))->click();

        self::assertTrue(self::displayed($confirm), 'the summary reveals the button');
        self::assertSame('Opravdu smazat', self::text($confirm));
        $article->findElement(WebDriverBy::cssSelector('.inbox-delete input[name="signature"]'))->sendKeys('Lung');
        self::follow($confirm);

        self::assertStringStartsWith('/' . self::SLUG . '/admin/notify', self::path(), 'back on the admin page');
        self::assertTrue(self::present('#inbox'), 'the section is there');
        self::assertStringNotContainsString($text, self::text('#inbox'), 'the message is gone');
    }

    /** Chooses the kind, types the text and sends the Zprávy view's form, waiting for the landing. */
    private static function fillAndSend(string $kind, string $text): void
    {
        self::$browser->findElement(WebDriverBy::cssSelector('input[name="kind"][value="' . $kind . '"]'))->click();
        self::$browser->findElement(WebDriverBy::cssSelector('#inbox-body'))->sendKeys($text);
        self::follow('form[data-inbox-form] button[type="submit"]');
    }
}
