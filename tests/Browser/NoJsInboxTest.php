<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

/**
 * Napiš organizátorům without JavaScript, on obrok19: the card on Informace is a link to
 * the form page (to Profil without a login), the form is a plain POST that lands on
 * Informace with the toast, and on the admin page a message is deleted in two steps, the
 * `Smazat…` summary first, with no script and no browser dialog. The admin link opens
 * with this class's own ADMIN_TOKEN_OBROK19.
 */
#[Group('browser')]
final class NoJsInboxTest extends NoJsTestCase
{
    private const string SLUG = 'obrok19';

    private const string ADMIN_TOKEN = 'nojs-inbox-admin-token';

    /** @return array<string, string> */
    protected static function serverEnvironment(): array
    {
        return ['ADMIN_TOKEN_OBROK19' => self::ADMIN_TOKEN] + parent::serverEnvironment();
    }

    public function testAPairedReaderSendsAMessageFromTheCardToTheToast(): void
    {
        self::pairWithoutScript(self::SLUG);
        self::visit('/' . self::SLUG . '/informace');

        self::follow('a[data-key="inbox-card"]');

        self::assertSame('/' . self::SLUG . '/zpravy', self::path(), 'the card leads to the form page');
        self::assertFalse(self::present('[data-toast]'));
        self::fillAndSend('help', 'Neteče voda ve sprchách u brány.');

        self::assertStringStartsWith('/' . self::SLUG . '/informace', self::path(), 'the send lands on Informace');
        self::assertTrue(self::displayed('[data-toast]'), 'the toast is shown');
        self::assertSame('Zpráva odeslána, díky!', self::text('[data-toast] strong'));
        // the help kind carries the event's promise line as the toast's second line
        self::assertSame('Zprávy čteme průběžně.', self::text('[data-toast] span'));
    }

    public function testWithoutALoginTheCardLeadsToProfil(): void
    {
        self::visit('/' . self::SLUG . '/');
        self::$browser->manage()->deleteAllCookies();
        self::visit('/' . self::SLUG . '/informace');

        self::assertStringContainsString('Napsat můžeš po přihlášení.', self::text('a[data-key="inbox-card"]'));
        self::follow('a[data-key="inbox-card"]');

        self::assertSame('/' . self::SLUG . '/profil', self::path(), 'logged out, the card leads to the login');
        self::assertTrue(self::present('form[action="/' . self::SLUG . '/profil/propojit"]'), 'Profil offers the login');
    }

    public function testAnEmptyTextIsStoppedOrAnsweredWithTheError(): void
    {
        self::pairWithoutScript(self::SLUG);
        self::visit('/' . self::SLUG . '/zpravy');
        self::$browser->findElement(WebDriverBy::cssSelector('input[name="kind"][value="idea"]'))->click();

        // the textarea is `required`, so the browser itself may refuse to send the form
        self::$browser->findElement(WebDriverBy::cssSelector('form[data-inbox-form] button[type="submit"]'))->click();
        self::$browser->findElement(WebDriverBy::cssSelector('html'));

        self::assertSame('/' . self::SLUG . '/zpravy', self::path(), 'still on the form page');
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
        self::visit('/' . self::SLUG . '/zpravy');
        // the help kind, newest first: the first message of the section
        self::fillAndSend('help', $text);
        self::assertStringStartsWith('/' . self::SLUG . '/informace', self::path());

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

    /** Chooses the kind, types the text and sends the form page's form, waiting for the landing. */
    private static function fillAndSend(string $kind, string $text): void
    {
        self::$browser->findElement(WebDriverBy::cssSelector('input[name="kind"][value="' . $kind . '"]'))->click();
        self::$browser->findElement(WebDriverBy::cssSelector('#inbox-body'))->sendKeys($text);
        self::follow('form[data-inbox-form] button[type="submit"]');
    }
}
