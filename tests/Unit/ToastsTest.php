<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Session;
use App\Toast\Toast;
use App\Toast\Toasts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\FixedClock;

final class ToastsTest extends TestCase
{
    private FixedClock $clock;

    private Session $session;

    private Toasts $toasts;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->clock = new FixedClock(new \DateTimeImmutable('2027-05-21T09:00:00+02:00'));
        $this->session = new Session();
        $this->toasts = new Toasts($this->session, $this->clock);
    }

    public function testAToastOlderThanAMinuteIsDroppedUnsaid(): void
    {
        $this->toasts->put('Zapsáno: Lanovka', 'Pá 21. 5. · 9:00–12:30');
        $this->clock->set($this->clock->now()->modify('+61 seconds'));
        self::assertNull($this->toasts->take($this->documentRequest()));
        self::assertFalse($this->session->has('toast'));
    }

    public function testAToastAMinuteOldIsStillSaid(): void
    {
        $this->toasts->put('Zapsáno: Lanovka', 'Pá 21. 5. · 9:00–12:30');
        $this->clock->set($this->clock->now()->modify('+60 seconds'));
        self::assertToast('Zapsáno: Lanovka', 'Pá 21. 5. · 9:00–12:30', $this->toasts->take($this->documentRequest()));
        self::assertFalse($this->session->has('toast'));
    }

    public function testAFragmentAndTheWorkersOwnFetchTakeNothing(): void
    {
        $this->toasts->put('Odhlášeno', null);
        self::assertNull($this->toasts->take($this->documentRequest(['X-Screen' => '1'])));
        self::assertNull($this->toasts->take($this->documentRequest([Toasts::WORKER_FILL_HEADER => '1'])));
        self::assertToast('Odhlášeno', null, $this->toasts->take($this->documentRequest()));
    }

    public function testARepeatedWritesRefusalDoesNotTalkOverItsOwnSuccess(): void
    {
        $this->toasts->put('Zapsáno: Lanovka', null, 'register:7');
        $this->toasts->putUnlessPending('Na program už jsi přihlášen.', null, 'register:7');
        self::assertSame('Zapsáno: Lanovka', $this->toasts->take($this->documentRequest())?->title);
    }

    public function testTheStoredShapeIsThePreviousReleases(): void
    {
        $this->toasts->put('A', 'b', 'register:7');
        self::assertSame(['title' => 'A', 'line' => 'b', 'at' => $this->clock->now()->getTimestamp(), 'tag' => 'register:7'], $this->session->get('toast'));
    }

    public function testARefusalWithNothingPendingIsStoredWithoutATag(): void
    {
        $this->toasts->putUnlessPending('Na program už jsi přihlášen.', null, 'register:7');
        // 2027-05-21T09:00:00+02:00, the fixed clock's time; the refusal carries no `tag` key
        self::assertSame(['title' => 'Na program už jsi přihlášen.', 'line' => null, 'at' => 1810882800], $this->session->get('toast'));
    }

    public function testTheLoginAndLogoutToastsKeepTheirStoredShape(): void
    {
        $at = $this->clock->now()->getTimestamp();
        $this->toasts->loggedIn();
        self::assertSame(['title' => 'Jsi přihlášen', 'line' => null, 'at' => $at], $this->session->get('toast'));
        $this->toasts->loggedOut();
        self::assertSame(['title' => 'Odhlášeno', 'line' => 'Obsah teď můžeš jen prohlížet', 'at' => $at], $this->session->get('toast'));
    }

    public function testAToastWrittenByThePreviousReleaseIsTaken(): void
    {
        // a session file from before the deploy: the literal shape the old Kernel wrote
        $this->session->set('toast', ['title' => 'Jsi přihlášen', 'line' => null, 'at' => $this->clock->now()->getTimestamp()]);
        self::assertToast('Jsi přihlášen', null, $this->toasts->take($this->documentRequest()));
    }

    public function testTheGoneToastSaysWhy(): void
    {
        self::assertToast('Odhlášeno', 'Propojení s KissJ už neplatí, přihlas se znovu.', $this->toasts->gone());
    }

    public function testOnlyAResponseCarryingAToastIsMarked(): void
    {
        $response = (new ResponseFactory())->createResponse();
        self::assertFalse($this->toasts->mark($response, null)->hasHeader(Toasts::HEADER));
        self::assertSame('1', $this->toasts->mark($response, new Toast('A'))->getHeaderLine(Toasts::HEADER));
    }

    /** @param array<string, string> $headers */
    /** Title and line compared with assertSame: assertEquals would take a '' line for null. */
    private static function assertToast(string $title, ?string $line, ?Toast $toast): void
    {
        self::assertNotNull($toast);
        self::assertSame($title, $toast->title);
        self::assertSame($line, $toast->line);
    }

    private function documentRequest(array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/korbo26/profil');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
