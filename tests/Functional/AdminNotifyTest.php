<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\PushSenderInterface;

final class AdminNotifyTest extends AppTestCase
{
    private SpyPushSender $sender;

    private \App\Push\MessageRepository $messages;

    private const SEND = ['token' => 'korbo-token', 'title' => 'Změna', 'body' => 'Začínáme v 15:00', 'signature' => 'Lung'];

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_TOKEN_OBROK19'] = 'tajny-token';
        $this->sender = new SpyPushSender();
        $this->messages = new \App\Push\MessageRepository(':memory:');
        $_ENV['ADMIN_TOKEN_KORBO26'] = 'korbo-token';
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK19'], $_ENV['ADMIN_TOKEN_OBROK27'], $_ENV['ADMIN_TOKEN_KORBO26'], $_ENV['ADMIN_TOKEN_MIQUIK26']);
        parent::tearDown();
    }

    private function app(): \Slim\App
    {
        return $this->createApp(overrides: [PushSenderInterface::class => $this->sender]);
    }

    private function korbo(array $overrides = []): \Slim\App
    {
        return $this->createApp('korbo26', overrides: $overrides + [
            PushSenderInterface::class => $this->sender,
            \App\Push\MessageRepository::class => $this->messages,
        ]);
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

    public function testFormRendersWithValidToken(): void
    {
        $response = $this->request($this->app(), 'GET', '/admin/notify?token=tajny-token');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Odeslat notifikaci', $html);
        // the token is carried into the form by a hidden field
        self::assertStringContainsString('name="token" value="tajny-token"', $html);
    }

    public function testFormWithoutTokenIs403(): void
    {
        self::assertSame(403, $this->request($this->app(), 'GET', '/admin/notify')->getStatusCode());
    }

    public function testWrongTokenPostIs403AndNothingSent(): void
    {
        $response = $this->request($this->app(), 'POST', '/admin/notify', [
            'token' => 'spatny', 'title' => 'Test', 'body' => 'Zprava',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->sender->calls);
    }

    public function testMissingAdminTokenConfigIs403(): void
    {
        $_ENV['ADMIN_TOKEN_OBROK19'] = '';

        $response = $this->request($this->app(), 'POST', '/admin/notify', [
            'token' => '', 'title' => 'Test', 'body' => 'Zprava',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCorrectTokenSends(): void
    {
        $response = $this->request($this->app(), 'POST', '/admin/notify', [
            'token' => 'tajny-token', 'title' => 'Zmena programu', 'body' => 'Koncert na stagi!', 'signature' => 'Lung',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertCount(1, $this->sender->calls);
        self::assertSame('Zmena programu', $this->sender->calls[0][0]);
        self::assertSame('obrok19', $this->sender->calls[0][3]);
    }

    public function testOneEventsTokenDoesNotOpenAnother(): void
    {
        $_ENV['ADMIN_TOKEN_OBROK27'] = 'other-token';
        $response = $this->request($this->createApp('obrok19'), 'GET', '/admin/notify?token=other-token');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnsetAdminTokenConfigIs403(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK19']);

        $response = $this->request($this->app(), 'GET', '/admin/notify?token=');

        self::assertSame(403, $response->getStatusCode());
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

        $response = $this->request($app, 'POST', '/admin/notify', [
            'token' => 'event-token', 'title' => 'Zmena programu', 'body' => 'Koncert na stagi!', 'signature' => 'Lung',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame($slug, $this->sender->calls[0][3]);
    }

    public function testAnEventWideSendIsLoggedAndLinksToNews(): void
    {
        $app = $this->korbo();
        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => '']);

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->sender->calls[0][5]);
        self::assertSame('/korbo26/novinky', $this->sender->calls[0][4]);
        $logged = $this->messages->page('korbo26', 1)[0];
        self::assertSame(['Změna', 'Lung', null, 'Všem'], [$logged['title'], $logged['signature'], $logged['programmeId'], $logged['targetLabel']]);
        self::assertNull($logged['unreached']);
        $html = (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token')->getBody();
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
        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => (string) $programme['id']]);

        self::assertSame(303, $response->getStatusCode());
        self::assertContains('KORBO1', $this->sender->calls[0][5]);
        self::assertSame(sprintf('/korbo26/programy#section-%d-program-%d', $programme['section']['id'], $programme['id']), $this->sender->calls[0][4]);
        $logged = $this->messages->page('korbo26', 1)[0];
        self::assertSame($programme['id'], $logged['programmeId']);
        self::assertSame($programme['name'], $logged['targetLabel']);
        // the in-memory subscription store is empty, so every registered participant is unreached
        self::assertSame(count($this->sender->calls[0][5]), $logged['unreached']);
        self::assertStringContainsString('přihlášených bez notifikací', (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token')->getBody());
    }

    public function testTheResultIsShownOnceAfterTheRedirectAndARefreshSendsNothing(): void
    {
        $app = $this->korbo();

        $post = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => '']);
        self::assertSame(303, $post->getStatusCode());
        self::assertStringContainsString('/korbo26/admin/notify?token=korbo-token', $post->getHeaderLine('Location'));

        $first = (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token')->getBody();
        // the result line is the <strong> one; the log below carries the same numbers in plain text
        self::assertStringContainsString('<strong>Odesláno: 2</strong>', $first);
        self::assertStringContainsString('value="Lung"', $first);

        $second = (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token')->getBody();
        self::assertStringNotContainsString('<strong>Odesláno: 2</strong>', $second);
        self::assertStringContainsString('Odesláno: 2', $second);
        self::assertStringNotContainsString('value="Lung"', $second);
        self::assertCount(1, $this->sender->calls);
        self::assertSame(1, $this->messages->count('korbo26'));
    }

    public function testAForgedProgrammeIsRejectedAndNothingIsSentOrLogged(): void
    {
        $response = $this->request($this->korbo(), 'POST', '/admin/notify', self::SEND + ['target' => '999999']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Vybraný program neexistuje.', (string) $response->getBody());
        self::assertSame([], $this->sender->calls);
        self::assertSame(0, $this->messages->count('korbo26'));
    }

    public function testTheLimitsAreEnforcedAndTheTextIsKept(): void
    {
        $response = $this->request($this->korbo(), 'POST', '/admin/notify', ['title' => str_repeat('a', 61), 'target' => ''] + self::SEND);

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
        $response = $this->request($this->korbo(), 'POST', '/admin/notify', ['signature' => '', 'target' => ''] + self::SEND);

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

        $response = $this->request(
            $this->korbo([\App\Program\ProgramProviderInterface::class => $provider]),
            'POST',
            '/admin/notify',
            self::SEND + ['target' => (string) $programme['id']],
        );

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

        $html = (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token')->getBody();
        self::assertStringContainsString('Programy se nepodařilo načíst, lze poslat jen všem.', $html);
        self::assertStringNotContainsString('<optgroup', $html);

        $response = $this->request($app, 'POST', '/admin/notify', self::SEND + ['target' => '1']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Vybraný program neexistuje.', (string) $response->getBody());
        self::assertSame([], $this->sender->calls);
    }

    public function testTheProgrammePickerListsTheEventsProgrammes(): void
    {
        $html = (string) $this->request($this->korbo(), 'GET', '/admin/notify?token=korbo-token')->getBody();

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

        $first = (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token')->getBody();
        self::assertStringContainsString('Starší', $first);
        self::assertStringContainsString('page=2', $first);
        self::assertStringContainsString('Zpráva č. 51.', $first);
        self::assertStringNotContainsString('Zpráva č. 1.', $first);
        self::assertStringNotContainsString('Novější', $first);

        $second = (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token&page=2')->getBody();
        self::assertStringContainsString('Zpráva č. 1.', $second);
        self::assertStringNotContainsString('Zpráva č. 2.', $second);
        self::assertStringContainsString('Novější', $second);
        self::assertStringNotContainsString('Starší', $second);
    }

    public function testHidingIsRecordedAndReversible(): void
    {
        $id = $this->messages->add('korbo26', null, 'Všem', 'Změna', 'text', 'Lung', 1, 0, null);
        $app = $this->korbo();

        $hide = $this->request($app, 'POST', '/admin/notify/' . $id . '/hidden', ['token' => 'korbo-token', 'hidden' => '1', 'signature' => 'Jana']);
        self::assertSame(303, $hide->getStatusCode());
        self::assertStringContainsString('/korbo26/admin/notify?token=korbo-token', $hide->getHeaderLine('Location'));
        self::assertSame([], $this->messages->visible('korbo26'));

        $html = (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token')->getBody();
        self::assertStringContainsString('Zobrazit v Novinkách', $html);
        self::assertStringContainsString('Skryto z Novinek (Jana', $html);

        $show = $this->request($app, 'POST', '/admin/notify/' . $id . '/hidden', ['token' => 'korbo-token', 'hidden' => '0', 'signature' => 'Petr']);
        self::assertSame(303, $show->getStatusCode());
        self::assertCount(1, $this->messages->visible('korbo26'));
        $html = (string) $this->request($app, 'GET', '/admin/notify?token=korbo-token')->getBody();
        self::assertStringContainsString('Znovu zobrazeno (Petr', $html);
        self::assertStringContainsString('Skrýt z Novinek', $html);
    }

    public function testHidingNeedsAPodpis(): void
    {
        $id = $this->messages->add('korbo26', null, 'Všem', 'Změna', 'text', 'Lung', 1, 0, null);

        $response = $this->request($this->korbo(), 'POST', '/admin/notify/' . $id . '/hidden', ['token' => 'korbo-token', 'hidden' => '1', 'signature' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Podpis je povinný a smí mít nejvýš 40 znaků.', (string) $response->getBody());
        self::assertCount(1, $this->messages->visible('korbo26'));
    }

    public function testHidingNeedsTheTokenAndTheEventsOwnMessage(): void
    {
        $id = $this->messages->add('korbo26', null, 'Všem', 'Změna', 'text', 'Lung', 1, 0, null);
        $app = $this->korbo();

        $forged = $this->request($app, 'POST', '/admin/notify/' . $id . '/hidden', ['token' => 'spatny', 'hidden' => '1', 'signature' => 'Jana']);
        self::assertSame(403, $forged->getStatusCode());
        self::assertCount(1, $this->messages->visible('korbo26'));

        $foreign = $this->messages->add('obrok27', null, 'Všem', 'Cizí', 'text', 'Lung', 1, 0, null);
        $response = $this->request($app, 'POST', '/admin/notify/' . $foreign . '/hidden', ['token' => 'korbo-token', 'hidden' => '1', 'signature' => 'Jana']);
        self::assertSame(404, $response->getStatusCode());
        self::assertCount(1, $this->messages->visible('obrok27'));
    }

    public function testMarkupInTheLogIsShownAsText(): void
    {
        $this->messages->add('korbo26', null, 'Všem', '<b>x</b>', 'text', 'Lung', 1, 0, null);

        $html = (string) $this->request($this->korbo(), 'GET', '/admin/notify?token=korbo-token')->getBody();

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
    }
}
