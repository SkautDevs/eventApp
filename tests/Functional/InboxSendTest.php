<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Inbox\InboxMessage;
use App\Inbox\InboxRepository;
use App\Inbox\Kind;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * POST /zpravy, the send of Napiš organizátorům on the `inbox` fixture event: the checks in
 * the spec's order (same origin, identity, validation, the throttle), the repeat answered
 * as a success, and the toast the redirect to Informace lands on.
 */
final class InboxSendTest extends AppTestCase
{
    private const array VALID = ['kind' => 'help', 'body' => 'Neteče sprcha v sektoru 3', 'contact' => ''];

    private \PDO $pdo;

    private function app(): App
    {
        $this->pdo = self::memoryDb();

        return $this->createApp('inbox', [\PDO::class => $this->pdo], fixtureEvent: true);
    }

    /** @param array<string, string> $body @param array<string, string> $headers */
    private function send(App $app, array $body = self::VALID, array $headers = []): ResponseInterface
    {
        return $this->request($app, 'POST', '/zpravy', $body, $headers);
    }

    /** @return list<InboxMessage> */
    private function rows(): array
    {
        return new InboxRepository($this->pdo)->unhandled('inbox');
    }

    /** The toast markup of a page, or null when it carries none. */
    private static function toast(string $html): ?string
    {
        return preg_match('#<div class="toast" role="status" data-toast data-key="toast">.*?</div>#s', $html, $m) === 1 ? $m[0] : null;
    }

    public function testAValidSendSavesRedirectsAndLeavesTheToast(): void
    {
        $app = $this->app();
        $this->loginAs($app, 42, 'Kája');

        $response = $this->send($app);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/inbox/informace#odeslano', $response->getHeaderLine('Location'));
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(42, $rows[0]->participantId);
        self::assertSame('Kája', $rows[0]->nickname);
        self::assertSame(Kind::Help, $rows[0]->kind);
        self::assertSame('Neteče sprcha v sektoru 3', $rows[0]->body);
        self::assertNull($rows[0]->contact);

        $page = $this->request($app, 'GET', '/informace');
        self::assertStringContainsString(
            '<p><strong>Zpráva odeslána, díky!</strong><span>Zprávy čteme ráno a večer.</span></p>',
            (string) self::toast((string) $page->getBody()),
        );
        self::assertSame('1', $page->getHeaderLine('X-Toast'));

        // said once
        $again = $this->request($app, 'GET', '/informace');
        self::assertNull(self::toast((string) $again->getBody()));
        self::assertSame('', $again->getHeaderLine('X-Toast'));
    }

    public function testAnIdeaLeavesTheToastWithoutASecondLine(): void
    {
        $app = $this->app();
        $this->loginAs($app, 42, 'Kája');

        self::assertSame(302, $this->send($app, ['kind' => 'idea'] + self::VALID)->getStatusCode());

        self::assertStringContainsString(
            '<p><strong>Zpráva odeslána, díky!</strong></p>',
            (string) self::toast((string) $this->request($app, 'GET', '/informace')->getBody()),
        );
    }

    public function testWithXWriteTheAnswerIsTheNextAddressAsJson(): void
    {
        $app = $this->app();
        $this->loginAs($app, 42, 'Kája');

        $response = $this->send($app, self::VALID, ['X-Write' => '1']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"next":"/inbox/informace#odeslano"}', (string) $response->getBody());
        self::assertCount(1, $this->rows());
    }

    public function testACrossSiteFormIsRefused(): void
    {
        $app = $this->app();
        $this->loginAs($app, 42, 'Kája');

        $response = $this->send($app, self::VALID, ['Sec-Fetch-Site' => 'cross-site']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Zprávu se nepodařilo odeslat. Načti stránku a zkus to znovu.', (string) $response->getBody());
        self::assertSame([], $this->rows());
    }

    public function testAReaderWhoIsNotLoggedInIsRefused(): void
    {
        $app = $this->app();

        $response = $this->send($app);
        $html = (string) $response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<p>Zprávu můžeš poslat až po přihlášení.</p>', $html);
        self::assertStringContainsString('<a class="highlight" href="/inbox/profil">Přihlásit se</a>', $html);
        self::assertSame([], $this->rows());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidForms(): iterable
    {
        yield 'no kind' => [['kind' => ''] + self::VALID, 'Vyber, o co jde.'];
        yield 'an unknown kind' => [['kind' => 'spam'] + self::VALID, 'Vyber, o co jde.'];
        yield 'an empty text' => [['body' => "  \r\n "] + self::VALID, 'Napiš zprávu.'];
        yield 'a text too long' => [['body' => str_repeat('ž', 1001)] + self::VALID, 'Zpráva smí mít nejvýš 1000 znaků.'];
        yield 'a contact too long' => [['contact' => str_repeat('č', 101)] + self::VALID, 'Kontakt smí mít nejvýš 100 znaků.'];
    }

    /** @param array<string, string> $body */
    #[DataProvider('invalidForms')]
    public function testEachValidationErrorReRendersTheFormWith422AndTheTypedValues(array $body, string $error): void
    {
        $app = $this->app();
        $this->loginAs($app, 42, 'Kája');

        $response = $this->send($app, $body);
        $html = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<p class="notice" role="alert">' . $error . '</p>', $html);
        self::assertSame(1, substr_count($html, 'role="alert"'), 'only the one error');
        self::assertStringContainsString('Píšeš jako Kája.', $html);
        $this->assertTheFormHolds($html, $body);
        self::assertSame([], $this->rows());
    }

    public function testTheSixthMessageInAnHourIs429(): void
    {
        $app = $this->app();
        $this->loginAs($app, 42, 'Kája');
        for ($i = 1; $i <= 5; ++$i) {
            self::assertSame(302, $this->send($app, ['body' => 'Zpráva ' . $i] + self::VALID)->getStatusCode());
        }

        $sixth = ['kind' => 'idea', 'body' => 'Zpráva 6', 'contact' => 'kaja@example.org'];
        $response = $this->send($app, $sixth);
        $html = (string) $response->getBody();

        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('<p class="notice" role="alert">Za hodinu můžeš poslat nejvýš 5 zpráv. Zkus to později.</p>', $html);
        $this->assertTheFormHolds($html, $sixth);
        self::assertCount(5, $this->rows());
    }

    public function testADoubleSendSavesOnce(): void
    {
        $app = $this->app();
        $this->loginAs($app, 42, 'Kája');

        $first = $this->send($app);
        $second = $this->send($app);

        self::assertSame(302, $first->getStatusCode());
        self::assertSame(302, $second->getStatusCode());
        self::assertSame('/inbox/informace#odeslano', $second->getHeaderLine('Location'));
        self::assertCount(1, $this->rows());
    }

    public function testATextOfAThousandCharactersWithCrLfIsAccepted(): void
    {
        $app = $this->app();
        $this->loginAs($app, 42, 'Kája');
        // 1000 characters once every \r\n is one \n, 1499 as the browser sends them
        $typed = implode("\r\n", array_fill(0, 500, 'ž')) . 'ž';

        $response = $this->send($app, ['body' => $typed] + self::VALID);

        self::assertSame(302, $response->getStatusCode());
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(1499, mb_strlen($typed));
        self::assertSame(1000, mb_strlen($rows[0]->body));
        self::assertSame(str_replace("\r\n", "\n", $typed), $rows[0]->body);
    }

    /**
     * The re-rendered form carries what the reader typed: the text, the chosen kind, the contact.
     *
     * @param array<string, string> $body
     */
    private function assertTheFormHolds(string $html, array $body): void
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $body['body']));
        self::assertStringContainsString('>' . htmlspecialchars($text) . '</textarea>', $html);
        self::assertSame(1, preg_match('/<input type="text" name="contact"[^>]*>/', $html, $contact));
        self::assertStringContainsString('value="' . htmlspecialchars(trim($body['contact'])) . '"', $contact[0]);
        preg_match_all('/<input\b[^>]*type="radio"[^>]*>/', $html, $radios);
        self::assertCount(2, $radios[0]);
        foreach ($radios[0] as $radio) {
            $chosen = str_contains($radio, 'value="' . $body['kind'] . '"');
            self::assertSame($chosen, str_contains($radio, ' checked'), $radio);
        }
    }
}
