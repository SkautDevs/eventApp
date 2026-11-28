<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Inbox\InboxRepository;
use App\Inbox\Kind;
use App\Module\Admin\AdminPageRenderer;
use App\Module\Admin\View\InboxSection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * The organisers' side of the participants' messages on the `inbox` fixture event: the
 * section of /admin/notify between the send form and the log, and its two POST actions,
 * marking a message handled (or returning it) and deleting it.
 */
final class AdminInboxTest extends AppTestCase
{
    private \PDO $pdo;

    private InboxRepository $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_TOKEN_INBOX'] = 'inbox-token';
        $_ENV['ADMIN_TOKEN_EMERGENCY'] = 'emergency-token';
        $this->pdo = self::memoryDb();
        $this->inbox = new InboxRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_TOKEN_INBOX'], $_ENV['ADMIN_TOKEN_EMERGENCY']);
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->createApp('inbox', $overrides + [\PDO::class => $this->pdo], fixtureEvent: true);
    }

    /** Opens the shared link as an organiser does; returns the CSRF token the forms now carry. */
    private function logIn(App $app, string $token = 'inbox-token'): string
    {
        $response = $this->request($app, 'GET', '/admin/notify?token=' . $token);
        self::assertSame(303, $response->getStatusCode());

        return (string) $_SESSION[ltrim($app->getBasePath(), '/')]['csrf'];
    }

    private function page(App $app, string $query = ''): string
    {
        $response = $this->request($app, 'GET', '/admin/notify' . $query);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function add(Kind $kind, string $body, string $at, ?string $nickname = 'Kája', int $participant = 42, ?string $contact = null, string $event = 'inbox'): int
    {
        return $this->inbox->add($event, $participant, $nickname, $kind, $body, $contact, new \DateTimeImmutable($at));
    }

    /** The markup of the section, from its opening tag to the log's heading. */
    private static function section(string $html): string
    {
        $start = strpos($html, 'id="inbox"');
        $end = strpos($html, '<h2>Odeslané zprávy</h2>');
        self::assertNotFalse($start, 'the page has the section');
        self::assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /** The markup of the article of the message that holds $text. */
    private static function article(string $html, string $text): string
    {
        foreach (explode('<article', $html) as $chunk) {
            if (str_contains($chunk, $text)) {
                return substr($chunk, 0, (int) strpos($chunk, '</article>'));
            }
        }
        self::fail('no message holds ' . $text);
    }

    /** @param array<string, string> $fields */
    private function post(App $app, string $path, array $fields): ResponseInterface
    {
        return $this->request($app, 'POST', $path, $fields);
    }

    public function testAnEventWithoutInboxShowsNoSection(): void
    {
        // a repository over a database without the table: any inbox query would fail the page
        $app = $this->createApp('emergency', [
            \PDO::class => $this->pdo,
            InboxRepository::class => new InboxRepository(new \PDO('sqlite::memory:')),
        ], fixtureEvent: true);
        $this->logIn($app, 'emergency-token');

        $html = $this->page($app);

        self::assertStringNotContainsString('id="inbox"', $html);
        self::assertStringNotContainsString('Zprávy od účastníků', $html);
    }

    public function testTheSectionSitsBetweenTheSendFormAndTheLog(): void
    {
        $app = $this->app();
        $this->logIn($app);

        $html = $this->page($app);

        $form = strpos($html, 'id="notify-form"');
        $section = strpos($html, 'id="inbox"');
        $log = strpos($html, 'Odeslané zprávy');
        self::assertNotFalse($form);
        self::assertNotFalse($section);
        self::assertNotFalse($log);
        self::assertLessThan($section, $form);
        self::assertLessThan($log, $section);
        self::assertStringContainsString('<h2>Zprávy od účastníků</h2>', self::section($html));
    }

    public function testTheLinkAtTheTopCarriesTheCount(): void
    {
        $this->add(Kind::Help, 'První', '2027-07-01 10:00:00');
        $this->add(Kind::Idea, 'Druhá', '2027-07-01 11:00:00');
        $handled = $this->add(Kind::Idea, 'Hotová', '2027-07-01 12:00:00');
        $this->inbox->setHandled('inbox', $handled, true, 'Lung');
        $app = $this->app();
        $this->logIn($app);

        $html = $this->page($app);

        self::assertMatchesRegularExpression('#<a href="\#inbox">Zprávy od účastníků \(2 nevyřízené\)</a>#', $html);
        self::assertLessThan(strpos($html, 'id="notify-form"'), strpos($html, 'href="#inbox"'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function counts(): iterable
    {
        yield 'none' => [0, '0 nevyřízených'];
        yield 'one' => [1, '1 nevyřízená'];
        yield 'two' => [2, '2 nevyřízené'];
        yield 'four' => [4, '4 nevyřízené'];
        yield 'five' => [5, '5 nevyřízených'];
    }

    #[DataProvider('counts')]
    public function testTheCountLineUsesTheCzechPlural(int $count, string $expected): void
    {
        self::assertSame($expected, InboxSection::unhandledLabel($count));
    }

    public function testUnhandledMessagesAreInTwoGroupsHelpFirst(): void
    {
        $this->add(Kind::Idea, 'Nápad starší', '2027-07-01 08:00:00');
        $this->add(Kind::Help, 'Pomoc starší', '2027-07-01 09:00:00');
        $this->add(Kind::Idea, 'Nápad novější', '2027-07-01 10:00:00');
        $this->add(Kind::Help, 'Pomoc novější', '2027-07-01 11:00:00');
        $app = $this->app();
        $this->logIn($app);

        $section = self::section($this->page($app));

        $help = strpos($section, 'Potřebují poradit nebo něco nefunguje');
        $ideas = strpos($section, 'Nápady a zpětná vazba');
        self::assertNotFalse($help);
        self::assertNotFalse($ideas);
        self::assertLessThan($ideas, $help);
        $positions = array_map(static fn(string $text): int|false => strpos($section, $text), ['Pomoc novější', 'Pomoc starší', 'Nápad novější', 'Nápad starší']);
        self::assertNotContains(false, $positions);
        self::assertLessThan($positions[1], $positions[0]);
        self::assertLessThan($ideas, $positions[1]);
        self::assertLessThan($positions[2], $ideas);
        self::assertLessThan($positions[3], $positions[2]);
    }

    public function testAGroupWithoutAMessageIsNotPrinted(): void
    {
        $this->add(Kind::Idea, 'Jen nápad', '2027-07-01 08:00:00');
        $app = $this->app();
        $this->logIn($app);

        $section = self::section($this->page($app));

        self::assertStringNotContainsString('Potřebují poradit nebo něco nefunguje', $section);
        self::assertStringContainsString('Nápady a zpětná vazba', $section);
        self::assertStringNotContainsString('Žádné nevyřízené zprávy.', $section);
    }

    public function testAMessageShowsItsSenderTimeTextAndContact(): void
    {
        $this->add(Kind::Help, 'Neteče sprcha', '2027-07-01 09:05:00', contact: '777 123 456');
        $this->add(Kind::Help, 'Bez kontaktu', '2027-07-01 10:00:00', nickname: null, participant: 7);
        $app = $this->app();
        $this->logIn($app);

        $section = self::section($this->page($app));

        $with = self::article($section, 'Neteče sprcha');
        self::assertStringContainsString('Kája · č. 42', $with);
        self::assertStringContainsString('1. 7. 2027 09:05', $with);
        self::assertStringContainsString('Kontakt: 777 123 456', $with);
        $without = self::article($section, 'Bez kontaktu');
        self::assertStringContainsString('Účastník · č. 7', $without);
        self::assertStringNotContainsString('Kontakt:', $without);
    }

    public function testAnEmptyInboxSaysSo(): void
    {
        $app = $this->app();
        $this->logIn($app);

        $section = self::section($this->page($app));

        self::assertStringContainsString('Žádné nevyřízené zprávy.', $section);
        self::assertStringNotContainsString('Potřebují poradit nebo něco nefunguje', $section);
        self::assertStringNotContainsString('Nápady a zpětná vazba', $section);
    }

    public function testTheSectionWarnsAboutPersonalData(): void
    {
        $app = $this->app();
        $this->logIn($app);

        self::assertStringContainsString(
            'Zprávy obsahují osobní údaje a samy se nemažou. Nepotřebné smazat.',
            self::section($this->page($app)),
        );
    }

    public function testATextIsEscapedAndKeepsItsLineBreaks(): void
    {
        $this->add(Kind::Help, "<script>alert(1)</script>\nřádek", '2027-07-01 09:00:00');
        $app = $this->app();
        $this->logIn($app);

        $section = self::section($this->page($app));

        self::assertStringNotContainsString('<script>alert(1)', $section);
        self::assertMatchesRegularExpression('#<p class="inbox-body">&lt;script&gt;alert\(1\)&lt;/script&gt;\nřádek</p>#', $section);
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/css/admin.css');
        self::assertMatchesRegularExpression('/\.inbox-body\s*\{[^}]*white-space:\s*pre-line;[^}]*\}/', $css);
        self::assertMatchesRegularExpression('/\.inbox-body\s*\{[^}]*overflow-wrap:\s*anywhere;[^}]*\}/', $css);
    }

    public function testHandledMessagesAreInAClosedDetailsWithTheirCount(): void
    {
        foreach (['Jedna', 'Dvě', 'Tři'] as $i => $text) {
            $id = $this->add(Kind::Idea, $text, '2027-07-01 0' . ($i + 1) . ':00:00');
            $this->inbox->setHandled('inbox', $id, true, 'Lung', new \DateTimeImmutable('2027-07-02 14:3' . $i . ':00'));
        }
        $app = $this->app();
        $this->logIn($app);

        $section = self::section($this->page($app));

        self::assertMatchesRegularExpression('#<details class="inbox-handled">\s*<summary>Vyřízené \(3\)</summary>#', $section);
        self::assertStringContainsString('Vyřízeno: Lung, 2. 7. 2027 14:30', self::article($section, 'Jedna'));
        self::assertStringContainsString('Vyřízeno: Lung, 2. 7. 2027 14:32', self::article($section, 'Tři'));
        self::assertLessThan(strpos($section, 'Jedna'), strpos($section, 'Tři'));
        self::assertStringContainsString('Žádné nevyřízené zprávy.', $section);
    }

    public function testTheHandledListPagesOnItsOwnParameter(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $id = $this->add(Kind::Help, 'Vyřízená č. ' . $i . '.', sprintf('2027-07-01 10:%02d:00', $i));
            $this->inbox->setHandled('inbox', $id, true, 'Lung');
        }
        $app = $this->app();
        $this->logIn($app);

        $first = self::section($this->page($app, '?page=3'));
        self::assertStringContainsString('Vyřízená č. 21.', $first);
        self::assertStringNotContainsString('Vyřízená č. 1.', $first);
        self::assertMatchesRegularExpression('#<a class="btn" href="/inbox/admin/notify\?page=3&amp;inbox=2\#inbox">Starší</a>#', $first);
        self::assertStringNotContainsString('Novější', $first);

        $second = self::section($this->page($app, '?page=3&inbox=2'));
        self::assertStringContainsString('Vyřízená č. 1.', $second);
        self::assertStringNotContainsString('Vyřízená č. 2.', $second);
        self::assertMatchesRegularExpression('#<details class="inbox-handled" open>\s*<summary>Vyřízené \(21\)</summary>#', $second);
        self::assertMatchesRegularExpression('#<a class="btn" href="/inbox/admin/notify\?page=3&amp;inbox=1\#inbox">Novější</a>#', $second);
        self::assertStringNotContainsString('Starší', $second);
        self::assertStringContainsString('name="inbox" value="2"', $second);
        self::assertStringContainsString('name="page" value="3"', $second);
        // inside the <details>
        self::assertLessThan(strpos($second, 'Novější'), strpos($second, '<details class="inbox-handled"'));
        self::assertLessThan(strpos($second, '</details>', (int) strpos($second, 'Novější')), strpos($second, 'Novější'));
    }

    /** The last message of a later handled page deleted: the redirect keeps `inbox=2`, and the list shows its last page that exists. */
    public function testAHandledPageBeyondTheLastShowsTheLastOne(): void
    {
        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            $ids[$i] = $this->add(Kind::Help, 'Vyřízená č. ' . $i . '.', sprintf('2027-07-01 10:%02d:00', $i));
            $this->inbox->setHandled('inbox', $ids[$i], true, 'Lung');
        }
        $app = $this->app();
        $csrf = $this->logIn($app);
        self::assertStringContainsString('Vyřízená č. 1.', self::section($this->page($app, '?page=1&inbox=2')), 'the oldest is alone on page 2');

        $response = $this->post($app, '/admin/inbox/' . $ids[1] . '/delete', ['csrf' => $csrf, 'signature' => 'Lung', 'page' => '1', 'inbox' => '2']);
        self::assertSame('/inbox/admin/notify?page=1&inbox=2#inbox', $response->getHeaderLine('Location'));

        $section = self::section($this->page($app, '?page=1&inbox=2'));
        self::assertMatchesRegularExpression('#<details class="inbox-handled" open>\s*<summary>Vyřízené \(20\)</summary>#', $section);
        self::assertStringContainsString('Vyřízená č. 21.', $section, 'page 1 is shown');
        self::assertStringContainsString('Vyřízená č. 2.', $section);
        self::assertStringContainsString('name="inbox" value="1"', $section, 'and its forms say so');
        self::assertStringNotContainsString('Novější', $section);
        self::assertStringNotContainsString('Starší', $section);
    }

    public function testMarkingHandledRecordsThePodpisAndRedirectsBack(): void
    {
        $id = $this->add(Kind::Help, 'Neteče sprcha', '2027-07-01 09:00:00');
        $app = $this->app();
        $csrf = $this->logIn($app);

        $response = $this->post($app, '/admin/inbox/' . $id . '/handled', [
            'csrf' => $csrf, 'signature' => 'Lung', 'handled' => '1', 'page' => '2', 'inbox' => '1',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/inbox/admin/notify?page=2&inbox=1#inbox', $response->getHeaderLine('Location'));
        self::assertSame([], $this->inbox->unhandled('inbox'));
        $handled = $this->inbox->handledPage('inbox', 1);
        self::assertCount(1, $handled);
        self::assertSame('Lung', $handled[0]->handledBy);
        self::assertNotNull($handled[0]->handledAt);
    }

    public function testAHandledMessageCanBeReturned(): void
    {
        $open = $this->add(Kind::Help, 'Otevřená', '2027-07-01 09:00:00');
        $done = $this->add(Kind::Help, 'Hotová', '2027-07-01 10:00:00');
        $this->inbox->setHandled('inbox', $done, true, 'Lung');
        $app = $this->app();
        $csrf = $this->logIn($app);

        $section = self::section($this->page($app));
        $openArticle = self::article($section, 'Otevřená');
        self::assertStringContainsString('name="handled" value="1"', $openArticle);
        self::assertMatchesRegularExpression('#<button type="submit" class="btn">Vyřízeno</button>#', $openArticle);
        $doneArticle = self::article($section, 'Hotová');
        self::assertStringContainsString('name="handled" value="0"', $doneArticle);
        self::assertStringContainsString('Vrátit mezi nevyřízené', $doneArticle);
        self::assertStringNotContainsString('Vrátit mezi nevyřízené', $openArticle);

        $response = $this->post($app, '/admin/inbox/' . $done . '/handled', [
            'csrf' => $csrf, 'signature' => 'Jana', 'handled' => '0', 'page' => '1', 'inbox' => '1',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(0, $this->inbox->countHandled('inbox'));
        $returned = array_values(array_filter($this->inbox->unhandled('inbox'), static fn($m): bool => $m->id === $done));
        self::assertCount(1, $returned);
        self::assertNull($returned[0]->handledBy);
        self::assertNull($returned[0]->handledAt);
    }

    public function testHandledNeedsAPodpis(): void
    {
        $id = $this->add(Kind::Help, 'Neteče sprcha', '2027-07-01 09:00:00');
        $app = $this->app();
        $csrf = $this->logIn($app);

        foreach (['', '   ', str_repeat('ž', 41)] as $signature) {
            $response = $this->post($app, '/admin/inbox/' . $id . '/handled', [
                'csrf' => $csrf, 'signature' => $signature, 'handled' => '1', 'page' => '1', 'inbox' => '1',
            ]);

            self::assertSame(422, $response->getStatusCode());
            $html = (string) $response->getBody();
            self::assertStringContainsString(AdminPageRenderer::SIGNATURE_ERROR, $html);
            // the page comes back whole, the section with it
            self::assertStringContainsString('Neteče sprcha', self::section($html));
            self::assertSame(1, $this->inbox->countUnhandled('inbox'));
        }
    }

    public function testDeleteNeedsAPodpis(): void
    {
        $id = $this->add(Kind::Help, 'Neteče sprcha', '2027-07-01 09:00:00');
        $app = $this->app();
        $csrf = $this->logIn($app);

        $response = $this->post($app, '/admin/inbox/' . $id . '/delete', ['csrf' => $csrf, 'signature' => '', 'page' => '1', 'inbox' => '1']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(AdminPageRenderer::SIGNATURE_ERROR, (string) $response->getBody());
        self::assertSame(1, $this->inbox->countUnhandled('inbox'));
    }

    public function testDeleteIsATwoStepReveal(): void
    {
        $open = $this->add(Kind::Help, 'Otevřená', '2027-07-01 09:00:00');
        $done = $this->add(Kind::Idea, 'Hotová', '2027-07-01 10:00:00');
        $this->inbox->setHandled('inbox', $done, true, 'Lung');
        $app = $this->app();
        $this->logIn($app);

        $section = self::section($this->page($app));

        foreach ([$open => 'Otevřená', $done => 'Hotová'] as $id => $text) {
            $article = self::article($section, $text);
            self::assertMatchesRegularExpression(
                '#<details class="inbox-delete">\s*<summary>Smazat…</summary>\s*<form method="post" action="/inbox/admin/inbox/' . $id . '/delete"[^>]*>.*?<button type="submit" class="btn">Opravdu smazat</button>\s*</form>\s*</details>#s',
                $article,
            );
        }
    }

    public function testEveryFormCarriesItsFieldsAndAUniquePodpis(): void
    {
        $this->add(Kind::Help, 'První', '2027-07-01 09:00:00');
        $this->add(Kind::Idea, 'Druhá', '2027-07-01 10:00:00');
        $app = $this->app();
        $csrf = $this->logIn($app);

        $section = self::section($this->page($app, '?page=2'));

        preg_match_all('#<form method="post".*?</form>#s', $section, $forms);
        self::assertCount(4, $forms[0]);
        $ids = [];
        foreach ($forms[0] as $form) {
            self::assertStringContainsString('name="csrf" value="' . $csrf . '"', $form);
            self::assertStringContainsString('name="page" value="2"', $form);
            self::assertStringContainsString('name="inbox" value="1"', $form);
            self::assertSame(1, preg_match('#<label for="([^"]+)">Podpis</label>\s*<input type="text" id="\1" name="signature" maxlength="40" required>#', $form, $m), $form);
            $ids[] = $m[1];
        }
        self::assertSame($ids, array_unique($ids));
    }

    public function testDeleteRemovesTheRowAndLogsNoText(): void
    {
        $id = $this->add(Kind::Help, 'Tajný text', '2027-07-01 09:00:00', contact: 'kaja@example.com');
        $log = new \Monolog\Handler\TestHandler();
        $app = $this->app([\Psr\Log\LoggerInterface::class => new \Monolog\Logger('eventapp', [$log])]);
        $csrf = $this->logIn($app);

        $response = $this->post($app, '/admin/inbox/' . $id . '/delete', ['csrf' => $csrf, 'signature' => 'Lung', 'page' => '1', 'inbox' => '3']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/inbox/admin/notify?page=1&inbox=3#inbox', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->inbox->countUnhandled('inbox'));
        self::assertSame(0, $this->inbox->countHandled('inbox'));
        $records = $log->getRecords();
        self::assertCount(1, $records);
        self::assertSame(['event' => 'inbox', 'id' => $id, 'by' => 'Lung'], $records[0]->context);
        $line = json_encode([$records[0]->message, $records[0]->context, $records[0]->extra], JSON_UNESCAPED_UNICODE);
        self::assertIsString($line);
        self::assertStringNotContainsString('Tajný text', $line);
        self::assertStringNotContainsString('kaja@example.com', $line);
    }

    public function testActionsOnAMissingOrForeignIdAre404(): void
    {
        $foreign = $this->add(Kind::Help, 'Cizí', '2027-07-01 09:00:00', event: 'obrok27');
        $app = $this->app();
        $csrf = $this->logIn($app);

        foreach (['handled', 'delete'] as $action) {
            foreach ([$foreign, 999] as $id) {
                $response = $this->post($app, '/admin/inbox/' . $id . '/' . $action, [
                    'csrf' => $csrf, 'signature' => 'Lung', 'handled' => '1', 'page' => '1', 'inbox' => '1',
                ]);
                self::assertSame(404, $response->getStatusCode(), $action . ' ' . $id);
                self::assertSame('', (string) $response->getBody(), $action . ' ' . $id);
            }
        }
        self::assertSame(1, $this->inbox->countUnhandled('obrok27'));
    }

    public function testActionsAreRefusedWithoutAnOpenSessionOrWithAStaleCsrf(): void
    {
        $id = $this->add(Kind::Help, 'Neteče sprcha', '2027-07-01 09:00:00');
        $fields = ['signature' => 'Lung', 'handled' => '1', 'page' => '1', 'inbox' => '1'];

        foreach (['handled', 'delete'] as $action) {
            // no session at all
            $_SESSION = [];
            $response = $this->post($this->app(), '/admin/inbox/' . $id . '/' . $action, $fields + ['csrf' => str_repeat('0', 32)]);
            self::assertSame(403, $response->getStatusCode(), $action);
            self::assertStringContainsString('Odkaz vypršel. Otevři prosím sdílený odkaz znovu.', (string) $response->getBody());

            // a session whose CSRF token is another
            $app = $this->app();
            $this->logIn($app);
            $response = $this->post($app, '/admin/inbox/' . $id . '/' . $action, $fields + ['csrf' => str_repeat('0', 32)]);
            self::assertSame(403, $response->getStatusCode(), $action);
            $html = (string) $response->getBody();
            self::assertStringContainsString('Odkaz vypršel. Otevři prosím sdílený odkaz znovu.', $html);
            self::assertStringNotContainsString('name="csrf"', $html);

            // a day old
            $csrf = $this->logIn($app);
            $_SESSION['inbox']['admin']['since'] = time() - 86400;
            $response = $this->post($app, '/admin/inbox/' . $id . '/' . $action, $fields + ['csrf' => $csrf]);
            self::assertSame(403, $response->getStatusCode(), $action);
        }
        self::assertSame(1, $this->inbox->countUnhandled('inbox'));
        self::assertSame(0, $this->inbox->countHandled('inbox'));
    }

    public function testActionsCarryTheAdminHeaders(): void
    {
        $handled = $this->add(Kind::Help, 'Jedna', '2027-07-01 09:00:00');
        $deleted = $this->add(Kind::Help, 'Dvě', '2027-07-01 10:00:00');
        $app = $this->app();
        $csrf = $this->logIn($app);
        $fields = ['csrf' => $csrf, 'signature' => 'Lung', 'handled' => '1', 'page' => '1', 'inbox' => '1'];

        $responses = [
            'handled' => $this->post($app, '/admin/inbox/' . $handled . '/handled', $fields),
            'delete' => $this->post($app, '/admin/inbox/' . $deleted . '/delete', $fields),
            'missing' => $this->post($app, '/admin/inbox/999/delete', $fields),
            'refused' => $this->post($app, '/admin/inbox/' . $handled . '/handled', ['csrf' => 'spatny'] + $fields),
        ];

        foreach ($responses as $what => $response) {
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), $what);
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'), $what);
        }
    }
}
