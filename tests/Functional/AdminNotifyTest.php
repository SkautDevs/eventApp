<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\MessageRepository;
use App\Push\PushSenderInterface;

final class AdminNotifyTest extends AppTestCase
{
    private SpyPushSender $sender;

    private \PDO $pdo;

    private MessageRepository $messages;

    private const SEND = ['title' => 'Změna', 'body' => 'Začínáme v 15:00', 'signature' => 'Lung'];

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_TOKEN_OBROK19'] = 'tajny-token';
        $_ENV['ADMIN_TOKEN_KORBO26'] = 'korbo-token';
        $this->sender = new SpyPushSender();
        $this->pdo = self::memoryDb();
        $this->messages = new MessageRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK19'], $_ENV['ADMIN_TOKEN_OBROK27'], $_ENV['ADMIN_TOKEN_KORBO26'], $_ENV['ADMIN_TOKEN_MIQUIK26']);
        parent::tearDown();
    }

    private function app(): \Slim\App
    {
        return $this->createApp(overrides: [PushSenderInterface::class => $this->sender, \PDO::class => $this->pdo]);
    }

    private function korbo(array $overrides = []): \Slim\App
    {
        return $this->createApp('korbo26', overrides: $overrides + [
            PushSenderInterface::class => $this->sender,
            \PDO::class => $this->pdo,
        ]);
    }

    /** Opens the shared link as an organiser does; returns the CSRF token the forms now carry. */
    private function logIn(\Slim\App $app, string $token): string
    {
        $response = $this->request($app, 'GET', '/admin/notify?token=' . $token);
        self::assertSame(303, $response->getStatusCode());

        return (string) $_SESSION[ltrim($app->getBasePath(), '/')]['csrf'];
    }

    /** A programme KORBO1 is registered for, read from the fixture rather than hardcoded. */
    private function korboProgramme(): array
    {
        $dir = dirname(__DIR__, 2) . '/events/korbo26/fixtures';
        $id = json_decode((string) file_get_contents($dir . '/registered.json'), true)['tie:KORBO1'][0];
        foreach (json_decode((string) file_get_contents($dir . '/programs.json'), true) as $programme) {
            if ($programme['id'] === $id) {
                return $programme;
            }
        }
        self::fail('fixture programme not found');
    }

    public function testTheLinkLogsTheBrowserInAndRedirectsToTheBareUrl(): void
    {
        $app = $this->app();

        $response = $this->request($app, 'GET', '/admin/notify?token=tajny-token');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/obrok19/admin/notify', $response->getHeaderLine('Location'));
        $page = $this->request($app, 'GET', '/admin/notify');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Odeslat notifikaci', (string) $page->getBody());
    }

    public function testTheBareUrlWithoutTheFlagIs403(): void
    {
        self::assertSame(403, $this->request($this->app(), 'GET', '/admin/notify')->getStatusCode());
    }

    public function testTheFormCarriesACsrfFieldAndNoToken(): void
    {
        $app = $this->app();
        $csrf = $this->logIn($app, 'tajny-token');

        $html = (string) $this->request($app, 'GET', '/admin/notify')->getBody();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $csrf);
        self::assertStringContainsString('name="csrf" value="' . $csrf . '"', $html);
        self::assertStringNotContainsString('name="token"', $html);
        self::assertStringNotContainsString('tajny-token', $html);
    }

    public function testAWrongTokenIs403AndFlagsNothing(): void
    {
        $app = $this->app();

        self::assertSame(403, $this->request($app, 'GET', '/admin/notify?token=spatny')->getStatusCode());
        self::assertArrayNotHasKey('admin', $_SESSION['obrok19'] ?? []);
        self::assertSame(403, $this->request($app, 'GET', '/admin/notify')->getStatusCode());
    }

    public function testAPostWithoutCsrfIs403AndNothingIsSent(): void
    {
        $app = $this->app();
        $this->logIn($app, 'tajny-token');

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->sender->calls);
    }

    public function testAPostWithTheWrongCsrfIs403AndNothingIsSent(): void
    {
        $app = $this->app();
        $this->logIn($app, 'tajny-token');

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['csrf' => str_repeat('0', 32)]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->sender->calls);
    }

    public function testReopeningTheLinkKeepsTheCsrfTokenOfTheFormAlreadyOpen(): void
    {
        $app = $this->app();
        $this->logIn($app, 'tajny-token');
        $first = $this->csrfOnPage($app);

        $this->logIn($app, 'tajny-token');
        $second = $this->csrfOnPage($app);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        self::assertSame($first, $second);
        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['csrf' => $first, 'target' => '']);
        self::assertSame(303, $response->getStatusCode());
        self::assertCount(1, $this->sender->calls);
    }

    public function testAMalformedCsrfInTheSessionIsReplacedOnTheHop(): void
    {
        $app = $this->app();
        $_SESSION['obrok19'] = ['csrf' => 'abc'];

        $csrf = $this->logIn($app, 'tajny-token');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $csrf);
    }

    public function testARefusedSendEchoesTheMessageOnAnExpiredLinkPage(): void
    {
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');
        $_SESSION['korbo26']['admin']['since'] = time() - 86400;

        $response = $this->request($app, 'POST', '/admin/notify', [
            'title' => 'Bouřka <b>', 'body' => 'Schovejte se & čekejte', 'signature' => 'Lung', 'target' => '', 'csrf' => $csrf,
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('Odkaz vypršel. Otevři prosím sdílený odkaz znovu.', $html);
        self::assertStringContainsString('Tvoje zpráva (zkopíruj si ji):', $html);
        self::assertStringContainsString('Bouřka &lt;b&gt;', $html);
        self::assertStringNotContainsString('Bouřka <b>', $html);
        self::assertStringContainsString('Schovejte se &amp; čekejte', $html);
        self::assertStringNotContainsString('name="csrf"', $html);
        self::assertSame([], $this->sender->calls);
    }

    public function testASendWithoutAnySessionShowsTheExpiredLinkPage(): void
    {
        $response = $this->request($this->app(), 'POST', '/admin/notify', self::SEND + ['csrf' => 'abc']);

        self::assertSame(403, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Odkaz vypršel. Otevři prosím sdílený odkaz znovu.', $html);
        self::assertStringContainsString('Změna', $html);
        self::assertStringContainsString('Začínáme v 15:00', $html);
    }

    public function testACsrfMismatchShowsTheExpiredLinkPageWithTheMessage(): void
    {
        $app = $this->app();
        $this->logIn($app, 'tajny-token');

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['csrf' => str_repeat('0', 32)]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('Odkaz vypršel. Otevři prosím sdílený odkaz znovu.', $html);
        self::assertStringContainsString('Tvoje zpráva (zkopíruj si ji):', $html);
        self::assertStringContainsString('Začínáme v 15:00', $html);
        self::assertSame([], $this->sender->calls);
    }

    public function testARefusedHideShowsTheExpiredLinkPageWithoutAnEcho(): void
    {
        $id = $this->messages->add(event: 'obrok19', programmeId: null, targetLabel: 'Všem', title: 'T', body: 'B', signature: 'Lung', sent: 1, removed: 0, unreached: null);

        $response = $this->request($this->app(), 'POST', '/admin/notify/' . $id . '/hidden', ['csrf' => 'abc', 'hidden' => '1', 'signature' => 'Lung']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('Odkaz vypršel. Otevři prosím sdílený odkaz znovu.', $html);
        self::assertStringNotContainsString('Tvoje zpráva', $html);
    }

    /** The CSRF token the rendered admin page carries in its send form. */
    private function csrfOnPage(\Slim\App $app): string
    {
        $html = (string) $this->request($app, 'GET', '/admin/notify')->getBody();
        self::assertSame(1, preg_match('/name="csrf" value="([^"]*)"/', $html, $m));

        return $m[1];
    }

    public function testAPostWithoutTheFlagIs403(): void
    {
        $_SESSION['obrok19'] = ['csrf' => 'abc'];

        $response = $this->request($this->app(), 'POST', '/admin/notify', self::SEND + ['csrf' => 'abc']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->sender->calls);
    }

    public function testAFlagOlderThanADayIs403(): void
    {
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');
        $_SESSION['korbo26']['admin']['since'] = time() - 86400;

        self::assertSame(403, $this->request($app, 'GET', '/admin/notify')->getStatusCode());
        self::assertSame(403, $this->request($app, 'POST', '/admin/notify', self::SEND + ['csrf' => $csrf, 'target' => ''])->getStatusCode());
        self::assertSame([], $this->sender->calls);

        $_SESSION['korbo26']['admin']['since'] = time() - 86000;
        self::assertSame(200, $this->request($app, 'GET', '/admin/notify')->getStatusCode());
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedFlags(): iterable
    {
        yield 'since as a string' => [['since' => (string) time()]];
        yield 'a bare true' => [true];
        yield 'since missing' => [[]];
        yield 'since null' => [['since' => null]];
        yield 'since in the future' => [['since' => time() + 3600]];
    }

    /** Review Focus 4 */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedFlags')]
    public function testAMalformedFlagIs403(mixed $flag): void
    {
        $_SESSION['korbo26'] = ['admin' => $flag, 'csrf' => 'abc'];
        $app = $this->korbo();

        self::assertSame(403, $this->request($app, 'GET', '/admin/notify')->getStatusCode());
        self::assertSame(403, $this->request($app, 'POST', '/admin/notify', self::SEND + ['csrf' => 'abc', 'target' => ''])->getStatusCode());
        self::assertSame([], $this->sender->calls);
    }

    public function testMissingAdminTokenConfigIs403(): void
    {
        $_ENV['ADMIN_TOKEN_OBROK19'] = '';

        self::assertSame(403, $this->request($this->app(), 'GET', '/admin/notify?token=')->getStatusCode());
    }

    public function testUnsetAdminTokenConfigIs403(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK19']);

        self::assertSame(403, $this->request($this->app(), 'GET', '/admin/notify?token=')->getStatusCode());
    }

    public function testAFlagStopsWorkingWhenTheTokenIsRemovedFromTheConfig(): void
    {
        $app = $this->app();
        $this->logIn($app, 'tajny-token');
        $_ENV['ADMIN_TOKEN_OBROK19'] = '';

        self::assertSame(403, $this->request($app, 'GET', '/admin/notify')->getStatusCode());
    }

    public function testALoggedInSessionSends(): void
    {
        $app = $this->app();
        $csrf = $this->logIn($app, 'tajny-token');

        $response = $this->request($app, 'POST', '/admin/notify', [
            'csrf' => $csrf, 'title' => 'Zmena programu', 'body' => 'Koncert na stagi!', 'signature' => 'Lung',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/obrok19/admin/notify', $response->getHeaderLine('Location'));
        self::assertCount(1, $this->sender->calls);
        self::assertSame('Zmena programu', $this->sender->calls[0][0]);
        self::assertSame('obrok19', $this->sender->calls[0][3]);
    }

    public function testOneEventsTokenDoesNotOpenAnother(): void
    {
        $_ENV['ADMIN_TOKEN_OBROK27'] = 'other-token';

        self::assertSame(403, $this->request($this->app(), 'GET', '/admin/notify?token=other-token')->getStatusCode());
    }

    public function testOneEventsFlagDoesNotOpenAnother(): void
    {
        $this->logIn($this->korbo(), 'korbo-token');

        self::assertSame(403, $this->request($this->app(), 'GET', '/admin/notify')->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function eventsWithPush(): iterable
    {
        yield 'korbo26' => ['korbo26'];
        yield 'miquik26' => ['miquik26'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eventsWithPush')]
    public function testTheEventSendsToItsOwnSubscribers(string $slug): void
    {
        $_ENV['ADMIN_TOKEN_' . strtoupper($slug)] = 'event-token';
        $app = $this->createApp($slug, overrides: [PushSenderInterface::class => $this->sender]);
        $csrf = $this->logIn($app, 'event-token');

        $response = $this->request($app, 'POST', '/admin/notify', [
            'csrf' => $csrf, 'title' => 'Zmena programu', 'body' => 'Koncert na stagi!', 'signature' => 'Lung',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame($slug, $this->sender->calls[0][3]);
    }

    public function testAnEventWideSendIsLoggedAndLinksToNews(): void
    {
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => '', 'csrf' => $csrf]);

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->sender->calls[0][5]);
        self::assertSame('/korbo26/novinky', $this->sender->calls[0][4]);
        $logged = $this->messages->page('korbo26', 1)[0];
        self::assertSame(['Změna', 'Lung', null, 'Všem'], [$logged['title'], $logged['signature'], $logged['programmeId'], $logged['targetLabel']]);
        self::assertNull($logged['unreached']);
        $html = (string) $this->request($app, 'GET', '/admin/notify')->getBody();
        self::assertStringContainsString('Odesláno: 2', $html);
        self::assertStringNotContainsString('přihlášených bez notifikací', $html);
        // the form comes back empty except Podpis
        self::assertStringContainsString('value="Lung"', $html);
        self::assertStringNotContainsString('value="Změna"', $html);
    }

    public function testAProgrammeSendReachesOnlyItsRegisteredParticipants(): void
    {
        $programme = $this->korboProgramme();
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => (string) $programme['id'], 'csrf' => $csrf]);

        self::assertSame(303, $response->getStatusCode());
        self::assertContains('KORBO1', $this->sender->calls[0][5]);
        self::assertSame(sprintf('/korbo26/programy#section-%d-program-%d', $programme['section']['id'], $programme['id']), $this->sender->calls[0][4]);
        $logged = $this->messages->page('korbo26', 1)[0];
        self::assertSame($programme['id'], $logged['programmeId']);
        self::assertSame($programme['name'], $logged['targetLabel']);
        // the in-memory subscription store is empty, so every registered participant is unreached
        self::assertSame(count($this->sender->calls[0][5]), $logged['unreached']);
        self::assertStringContainsString('přihlášených bez notifikací', (string) $this->request($app, 'GET', '/admin/notify')->getBody());
    }

    public function testTheResultIsShownOnceAfterTheRedirectAndARefreshSendsNothing(): void
    {
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $post = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => '', 'csrf' => $csrf]);
        self::assertSame(303, $post->getStatusCode());
        self::assertSame('/korbo26/admin/notify', $post->getHeaderLine('Location'));

        $first = (string) $this->request($app, 'GET', '/admin/notify')->getBody();
        // the result line is the <strong> one; the log below carries the same numbers in plain text
        self::assertStringContainsString('<strong>Odesláno: 2</strong>', $first);
        self::assertStringContainsString('value="Lung"', $first);

        $second = (string) $this->request($app, 'GET', '/admin/notify')->getBody();
        self::assertStringNotContainsString('<strong>Odesláno: 2</strong>', $second);
        self::assertStringContainsString('Odesláno: 2', $second);
        self::assertStringNotContainsString('value="Lung"', $second);
        self::assertCount(1, $this->sender->calls);
        self::assertSame(1, $this->messages->count('korbo26'));
    }

    public function testAForgedProgrammeIsRejectedAndNothingIsSentOrLogged(): void
    {
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => '999999', 'csrf' => $csrf]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Vybraný program neexistuje.', (string) $response->getBody());
        self::assertSame([], $this->sender->calls);
        self::assertSame(0, $this->messages->count('korbo26'));
    }

    public function testTheLimitsAreEnforcedAndTheTextIsKept(): void
    {
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $response = $this->request($app, 'POST', '/admin/notify', ['title' => str_repeat('a', 61), 'target' => '', 'csrf' => $csrf] + self::SEND);

        self::assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Titulek je povinný a smí mít nejvýš 60 znaků.', $html);
        self::assertStringNotContainsString('Zpráva je povinná', $html);
        self::assertMatchesRegularExpression('~<textarea[^>]*name="body"[^>]*>Začínáme v 15:00</textarea>~', $html);
        self::assertStringContainsString('value="' . str_repeat('a', 61) . '"', $html);
        self::assertSame([], $this->sender->calls);
        self::assertSame(0, $this->messages->count('korbo26'));
    }

    public function testPodpisIsRequired(): void
    {
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $response = $this->request($app, 'POST', '/admin/notify', ['signature' => '', 'target' => '', 'csrf' => $csrf] + self::SEND);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Podpis je povinný a smí mít nejvýš 40 znaků.', (string) $response->getBody());
        self::assertSame([], $this->sender->calls);
    }

    public function testKissjFailingToListParticipantsSendsNothing(): void
    {
        $stub = new \App\Program\StubProgramProvider(dirname(__DIR__, 2) . '/events/korbo26/fixtures');
        $provider = new class ($stub) implements \App\Program\ProgramProviderInterface {
            public function __construct(private readonly \App\Program\StubProgramProvider $stub)
            {
            }

            public function getPrograms(): array
            {
                return $this->stub->getPrograms();
            }

            public function getSections(): array
            {
                return $this->stub->getSections();
            }

            public function getProgramsForIdentity(\App\Auth\Identity $identity): array
            {
                return $this->stub->getProgramsForIdentity($identity);
            }

            public function getTieCodesForProgramme(int $programmeId): array
            {
                throw new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', 'x'));
            }
        };
        $programme = $this->korboProgramme();
        $app = $this->korbo([\App\Program\ProgramProviderInterface::class => $provider]);
        $csrf = $this->logIn($app, 'korbo-token');

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => (string) $programme['id'], 'csrf' => $csrf]);

        self::assertSame(502, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Nepodařilo se načíst přihlášené z kissj, nic nebylo odesláno.', $html);
        self::assertStringContainsString('Začínáme v 15:00</textarea>', $html);
        self::assertSame([], $this->sender->calls);
        self::assertSame(0, $this->messages->count('korbo26'));
    }

    public function testAProgrammeOutageLeavesOnlyEveryone(): void
    {
        $provider = new ThrowingProgramProvider(
            programsException: new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', 'x')),
        );
        $app = $this->korbo([\App\Program\ProgramProviderInterface::class => $provider]);
        $csrf = $this->logIn($app, 'korbo-token');

        $html = (string) $this->request($app, 'GET', '/admin/notify')->getBody();
        self::assertStringContainsString('Programy se nepodařilo načíst, lze poslat jen všem.', $html);
        self::assertStringNotContainsString('<optgroup', $html);

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => '1', 'csrf' => $csrf]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Vybraný program neexistuje.', (string) $response->getBody());
        self::assertSame([], $this->sender->calls);
    }

    public function testTheProgrammePickerListsTheEventsProgrammes(): void
    {
        $app = $this->korbo();
        $this->logIn($app, 'korbo-token');

        $html = (string) $this->request($app, 'GET', '/admin/notify')->getBody();

        self::assertStringContainsString('<optgroup', $html);
        self::assertStringContainsString('<option value="">Všem odběratelům</option>', $html);
        self::assertStringContainsString(htmlspecialchars($this->korboProgramme()['name']), $html);
        // korbo26 opens on Wednesday 16. 9. 2026 with Volejbal at 08:00
        self::assertStringContainsString('label="středa 16. 9."', $html);
        self::assertStringContainsString('08:00 Volejbal – Volejbalové hřiště', $html);
    }

    public function testTheLogPagesByFifty(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->messages->add('korbo26', null, 'Všem', 'Zpráva č. ' . $i . '.', 'text', 'Lung', 1, 0, null);
        }
        $app = $this->korbo();
        $this->logIn($app, 'korbo-token');

        $first = (string) $this->request($app, 'GET', '/admin/notify')->getBody();
        self::assertStringContainsString('Starší', $first);
        self::assertStringContainsString('href="/korbo26/admin/notify?page=2"', $first);
        self::assertStringNotContainsString('token=', $first);
        self::assertStringContainsString('Zpráva č. 51.', $first);
        self::assertStringNotContainsString('Zpráva č. 1.', $first);
        self::assertStringNotContainsString('Novější', $first);

        $second = (string) $this->request($app, 'GET', '/admin/notify?page=2')->getBody();
        self::assertStringContainsString('Zpráva č. 1.', $second);
        self::assertStringNotContainsString('Zpráva č. 2.', $second);
        self::assertStringContainsString('Novější', $second);
        self::assertStringNotContainsString('Starší', $second);
    }

    public function testHidingIsRecordedAndReversible(): void
    {
        $id = $this->messages->add('korbo26', null, 'Všem', 'Změna', 'text', 'Lung', 1, 0, null);
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $hide = $this->request($app, 'POST', '/admin/notify/' . $id . '/hidden', ['csrf' => $csrf, 'hidden' => '1', 'signature' => 'Jana', 'page' => '1']);
        self::assertSame(303, $hide->getStatusCode());
        self::assertSame('/korbo26/admin/notify?page=1', $hide->getHeaderLine('Location'));
        self::assertSame([], $this->messages->visible('korbo26'));

        $html = (string) $this->request($app, 'GET', '/admin/notify')->getBody();
        self::assertStringContainsString('Zobrazit v Novinkách', $html);
        self::assertStringContainsString('Skryto z Novinek (Jana', $html);

        $show = $this->request($app, 'POST', '/admin/notify/' . $id . '/hidden', ['csrf' => $csrf, 'hidden' => '0', 'signature' => 'Petr']);
        self::assertSame(303, $show->getStatusCode());
        self::assertCount(1, $this->messages->visible('korbo26'));
        $html = (string) $this->request($app, 'GET', '/admin/notify')->getBody();
        self::assertStringContainsString('Znovu zobrazeno (Petr', $html);
        self::assertStringContainsString('Skrýt z Novinek', $html);
    }

    public function testHidingNeedsAPodpis(): void
    {
        $id = $this->messages->add('korbo26', null, 'Všem', 'Změna', 'text', 'Lung', 1, 0, null);
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $response = $this->request($app, 'POST', '/admin/notify/' . $id . '/hidden', ['csrf' => $csrf, 'hidden' => '1', 'signature' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Podpis je povinný a smí mít nejvýš 40 znaků.', (string) $response->getBody());
        self::assertCount(1, $this->messages->visible('korbo26'));
    }

    public function testHidingNeedsTheSessionAndTheEventsOwnMessage(): void
    {
        $id = $this->messages->add('korbo26', null, 'Všem', 'Změna', 'text', 'Lung', 1, 0, null);
        $app = $this->korbo();
        $csrf = $this->logIn($app, 'korbo-token');

        $forged = $this->request($app, 'POST', '/admin/notify/' . $id . '/hidden', ['csrf' => 'spatny', 'hidden' => '1', 'signature' => 'Jana']);
        self::assertSame(403, $forged->getStatusCode());
        self::assertCount(1, $this->messages->visible('korbo26'));

        $foreign = $this->messages->add('obrok27', null, 'Všem', 'Cizí', 'text', 'Lung', 1, 0, null);
        $response = $this->request($app, 'POST', '/admin/notify/' . $foreign . '/hidden', ['csrf' => $csrf, 'hidden' => '1', 'signature' => 'Jana']);
        self::assertSame(404, $response->getStatusCode());
        self::assertCount(1, $this->messages->visible('obrok27'));
    }

    public function testMarkupInTheLogIsShownAsText(): void
    {
        $this->messages->add('korbo26', null, 'Všem', '<b>x</b>', 'text', 'Lung', 1, 0, null);
        $app = $this->korbo();
        $this->logIn($app, 'korbo-token');

        $html = (string) $this->request($app, 'GET', '/admin/notify')->getBody();

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testTheAdminResponsesAreNotCachedNorReferred(): void
    {
        $app = $this->korbo();
        $denied = $this->request($app, 'GET', '/admin/notify');
        $redirect = $this->request($app, 'GET', '/admin/notify?token=korbo-token');
        $csrf = (string) $_SESSION['korbo26']['csrf'];
        $page = $this->request($app, 'GET', '/admin/notify');
        $sent = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => '', 'csrf' => $csrf]);

        foreach (['403 denial' => $denied, 'token redirect' => $redirect, 'page' => $page, 'send redirect' => $sent] as $what => $response) {
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), $what);
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'), $what);
        }
        $news = $this->request($app, 'GET', '/novinky');
        self::assertSame('same-origin', $news->getHeaderLine('Referrer-Policy'));
        self::assertNotSame('no-store', $news->getHeaderLine('Cache-Control'));
    }

    public function testASendWritesOneLogLineWithoutItsText(): void
    {
        $log = new \Monolog\Handler\TestHandler();
        $app = $this->korbo([\Psr\Log\LoggerInterface::class => new \Monolog\Logger('eventapp', [$log])]);
        $csrf = $this->logIn($app, 'korbo-token');
        $programme = $this->korboProgramme();

        $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => (string) $programme['id'], 'csrf' => $csrf]);

        $records = $log->getRecords();
        self::assertCount(1, $records);
        self::assertSame('push.sent', $records[0]->message);
        self::assertSame(\Monolog\Level::Info, $records[0]->level);
        // no TIE code, no title, no body
        self::assertSame(['event' => 'korbo26', 'sent' => 2, 'removed' => 1, 'programme' => $programme['id']], $records[0]->context);
    }
}
