<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;

/**
 * The form page's draft (www/js/inbox/index.js) in the running app, on obrok19, paired:
 * a send with no signal comes back from the worker's copy of the form page with the
 * offline toast and the typed message put back, a confirmed send forgets it, a reload
 * keeps it, and a logout or another reader's draft leaves nothing for the next reader.
 * The network is taken away by stopping the PHP server, as in OfflineTest. With the
 * script on, the admin page remembers the Podpis typed on a message's `Vyřízeno` form;
 * the admin link opens with this class's own ADMIN_TOKEN_OBROK19.
 */
#[Group('browser')]
final class InboxDraftTest extends BrowserTestCase
{
    private const string SLUG = 'obrok19';

    private const string FORM = '/obrok19/zpravy';

    /** The draft's key: 'inboxDraft:' plus the event-base meta, which ends in a slash. */
    private const string KEY = 'inboxDraft:/obrok19/';

    private const string ADMIN_TOKEN = 'inbox-draft-admin-token';

    /** @return array<string, string> */
    protected static function serverEnvironment(): array
    {
        return ['ADMIN_TOKEN_OBROK19' => self::ADMIN_TOKEN] + parent::serverEnvironment();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // the previous test took it away
        self::startServer();
    }

    public function testAnOfflineSendKeepsTheTextAndSaysWhy(): void
    {
        // the worker in control first: the login's purge then refills the paired pages
        self::visit('/' . self::SLUG . '/');
        $cache = self::waitForPrecache(self::SLUG);
        self::pair(self::SLUG);
        self::openEmptyForm();
        // the worker's copy of the form page is the paired one, with the form in it
        self::waitForCachedPage($cache, self::FORM, 'data-inbox-form');

        self::stopServer();
        self::tap('input[name="kind"][value="help"]');
        self::type('#inbox-body', 'Nefunguje mi přihlášení na program.');
        self::tap('form[data-inbox-form] button[type="submit"]');

        self::waitFor('const t = document.querySelector("[data-toast]"); return t !== null && t.textContent.includes(arguments[0]);', ['Akci není možné provést, protože jsi offline.']);
        self::assertSame(self::FORM, self::script('return location.pathname;'));
        self::assertSame('', self::script('return location.hash;'), 'no #offline left behind');
        self::assertSame('Nefunguje mi přihlášení na program.', self::script('return document.querySelector("#inbox-body").value;'), 'the text is back');
        self::assertTrue(self::script('return document.querySelector(\'input[name="kind"][value="help"]\').checked;'), 'the kind is still chosen');
    }

    public function testAConfirmedSendClearsTheDraft(): void
    {
        self::pair(self::SLUG);
        self::openEmptyForm();
        self::tap('input[name="kind"][value="idea"]');
        self::type('#inbox-body', 'Díky za skvělou akci.');
        self::type('#inbox-contact', 'kaja@example.com');
        self::assertNotNull(self::script('return localStorage.getItem(arguments[0]);', [self::KEY]), 'typing saved a draft');

        self::tap('form[data-inbox-form] button[type="submit"]');

        self::waitFor('const t = document.querySelector("[data-toast]"); return location.pathname === arguments[1] && t !== null && t.textContent.includes(arguments[0]);', ['Zpráva odeslána, díky!', '/obrok19/informace']);
        self::waitFor('return location.hash === "";');
        self::assertStringEndsWith('/obrok19/informace', (string) self::script('return location.href;'), 'no hash left in the address');
        self::assertNull(self::script('return localStorage.getItem(arguments[0]);', [self::KEY]), 'the draft is gone');

        self::visit(self::FORM);
        self::waitFor('return document.readyState === "complete" && document.querySelector("form[data-inbox-form]") !== null;');
        self::assertSame(
            ['', '', false, false],
            self::script('return [document.querySelector("#inbox-body").value, document.querySelector("#inbox-contact").value, document.querySelector(\'input[value="help"]\').checked, document.querySelector(\'input[value="idea"]\').checked];'),
            'the form is empty',
        );
    }

    public function testTheDraftSurvivesAReload(): void
    {
        self::pair(self::SLUG);
        self::openEmptyForm();
        self::type('#inbox-body', 'Rozepsaná zpráva');

        self::$browser->reload();
        self::waitFor('return document.readyState === "complete" && document.querySelector("form[data-inbox-form]") !== null;');

        self::assertSame('Rozepsaná zpráva', self::script('return document.querySelector("#inbox-body").value;'));
    }

    /** A participant's draft is theirs: a logout removes it, and a draft of another participant is neither shown nor kept. */
    public function testADraftIsNotLeftForTheNextReader(): void
    {
        self::pair(self::SLUG);
        self::openEmptyForm();
        self::type('#inbox-body', 'Soukromá zpráva');
        $identity = (string) self::script('return document.querySelector(\'meta[name="push-identity"]\').content;');
        self::assertSame($identity, self::script('return JSON.parse(localStorage.getItem(arguments[0])).owner;', [self::KEY]), 'the draft names its owner');

        self::visit('/' . self::SLUG . '/profil');
        self::waitFor('return document.readyState === "complete";');
        $logout = '[data-screen="/' . self::SLUG . '/profil"] .profile-logout button';
        self::tap($logout);
        self::tap($logout);
        self::waitFor('const m = document.querySelector(\'meta[name="push-identity"]\'); return document.readyState === "complete" && m !== null && m.content === "";');
        self::assertNull(self::script('return localStorage.getItem(arguments[0]);', [self::KEY]), 'the logout left no draft');

        self::pair(self::SLUG);
        self::visit(self::FORM);
        self::waitFor('return document.readyState === "complete";');
        self::script('localStorage.setItem(arguments[0], JSON.stringify({owner: "999999", kind: "idea", body: "Cizí zpráva", contact: "cizi@example.com"}));', [self::KEY]);
        self::visit(self::FORM);
        self::waitFor('return document.readyState === "complete" && document.querySelector("form[data-inbox-form]") !== null;');

        self::assertSame(['', ''], self::script('return [document.querySelector("#inbox-body").value, document.querySelector("#inbox-contact").value];'), 'another participant\'s draft is not put back');
        self::assertNull(self::script('return localStorage.getItem(arguments[0]);', [self::KEY]), 'and it is removed');
    }

    /** The Podpis typed on a message's Vyřízeno form is remembered, and the next admin page fills every Podpis field with it. */
    public function testTheAdminPageRemembersThePodpisOfAnInboxAction(): void
    {
        $text = 'Vyřídit s podpisem ' . bin2hex(random_bytes(4));
        self::pair(self::SLUG);
        self::openEmptyForm();
        self::tap('input[name="kind"][value="help"]');
        self::type('#inbox-body', $text);
        self::tap('form[data-inbox-form] button[type="submit"]');
        self::waitFor('const t = document.querySelector("[data-toast]"); return location.pathname === arguments[1] && t !== null && t.textContent.includes(arguments[0]);', ['Zpráva odeslána, díky!', '/obrok19/informace']);

        self::visit('/' . self::SLUG . '/admin/notify?token=' . self::ADMIN_TOKEN);
        self::waitFor('return document.readyState === "complete" && location.pathname === arguments[0];', ['/obrok19/admin/notify']);
        self::script('localStorage.removeItem("notifySignature");');
        self::visit('/' . self::SLUG . '/admin/notify#inbox');
        self::waitFor('return document.readyState === "complete" && document.querySelector("#inbox") !== null;');
        $article = self::$browser->findElement(WebDriverBy::xpath('//article[contains(@class, "inbox-message")][.//p[contains(@class, "inbox-body")][. = "' . $text . '"]]'));
        $article->findElement(WebDriverBy::cssSelector('form[action$="/handled"] input[name="signature"]'))->sendKeys('Lung');

        $article->findElement(WebDriverBy::cssSelector('form[action$="/handled"] button[type="submit"]'))->click();

        self::waitFor('return document.readyState === "complete" && location.search.includes("inbox=");');
        self::assertSame('Lung', self::script('return localStorage.getItem("notifySignature");'), 'the Podpis is stored');
        self::visit('/' . self::SLUG . '/admin/notify');
        self::waitFor('return document.readyState === "complete" && document.querySelector("#inbox") !== null;');
        $values = self::script('return Array.from(document.querySelectorAll(\'input[name="signature"]\')).map(input => input.value);');
        self::assertIsArray($values);
        self::assertGreaterThan(1, count($values), 'the send form and the message actions');
        self::assertSame(array_fill(0, count($values), 'Lung'), $values, 'every Podpis field is filled');
    }

    /** The form page with no draft from an earlier test: the key removed, then the page loaded again. */
    private static function openEmptyForm(): void
    {
        self::visit(self::FORM);
        self::waitFor('return document.readyState === "complete";');
        self::script('localStorage.removeItem(arguments[0]);', [self::KEY]);
        self::visit(self::FORM);
        self::waitFor('return document.readyState === "complete" && document.querySelector("form[data-inbox-form]") !== null;');
        self::assertSame('', self::script('return document.querySelector("#inbox-body").value;'));
    }

    /** Types into a field as a reader does, so its input events save the draft. */
    private static function type(string $selector, string $text): void
    {
        self::$browser->findElement(WebDriverBy::cssSelector($selector))->sendKeys($text);
    }

    private static function waitForCachedPage(string $cache, string $path, string $needle): void
    {
        self::waitForAsync(<<<'JS'
            const done = arguments[arguments.length - 1];
            caches.open(arguments[0])
                .then(cache => cache.match(arguments[1], {ignoreVary: true}))
                .then(response => (response ? response.text() : ''))
                .then(html => done(html.includes(arguments[2])), () => done(false));
            JS, [$cache, $path, $needle]);
    }
}
