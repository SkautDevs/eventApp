<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\PairingState;
use App\Program\Pairing;
use App\Session;
use App\Toast\Toasts;
use PHPUnit\Framework\TestCase;
use Tests\FixedClock;

final class PairingStateTest extends TestCase
{
    private FixedClock $clock;

    private Session $session;

    private Toasts $toasts;

    private PairingState $pairing;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->clock = new FixedClock(new \DateTimeImmutable('2027-05-21T09:00:00+02:00'));
        $this->session = new Session();
        $this->toasts = new Toasts($this->session, $this->clock);
        $this->pairing = new PairingState($this->session, $this->clock);
    }

    public function testAnOutcomeLineLastsTenMinutes(): void
    {
        $this->pairing->fail('busy');
        self::assertSame('Teď se propojuje moc lidí najednou, zkus to za chvíli.', $this->pairing->outcomeLine());
        $this->clock->set($this->clock->now()->modify('+600 seconds'));
        self::assertNull($this->pairing->outcomeLine());
    }

    public function testASessionThatIsGoneDropsAPendingLoginToast(): void
    {
        $this->toasts->loggedIn();
        $this->pairing->gone();
        self::assertFalse($this->session->has('toast'));
        self::assertSame('Propojení s KissJ už neplatí, přihlas se znovu.', $this->pairing->outcomeLine());
    }

    public function testTheGoneOutcomeIsStoredInThePreviousReleasesShape(): void
    {
        $this->pairing->gone();
        // 2027-05-21T09:00:00+02:00, the fixed clock's time, as the Unix timestamp the old Kernel wrote
        self::assertSame(['code' => 'gone', 'at' => 1810882800], $this->session->get('pairingOutcome'));
    }

    public function testAPairingOfAnotherShapeIsNoPairing(): void
    {
        $this->session->set('pairing', ['deviceCode' => 'x']);
        self::assertNull($this->pairing->pending());
    }

    public function testTheStoredShapesAreThePreviousReleases(): void
    {
        $this->pairing->fail('unavailable');
        $this->pairing->begin(new Pairing('d3v1c3', '483291', 'https://kissj.example/pair', new \DateTimeImmutable('2027-05-21T09:10:00+02:00'), 5));
        self::assertSame(['deviceCode' => 'd3v1c3', 'userCode' => '483291', 'verificationUrl' => 'https://kissj.example/pair', 'expiresAt' => '2027-05-21T09:10:00+02:00'], $this->session->get('pairing'));
        // a start drops the outcome before it
        self::assertFalse($this->session->has('pairingOutcome'));

        $this->pairing->fail('expired');
        self::assertFalse($this->session->has('pairing'));
        self::assertSame(['code' => 'expired', 'at' => $this->clock->now()->getTimestamp()], $this->session->get('pairingOutcome'));
    }

    public function testAPairingWrittenByThePreviousReleaseIsRead(): void
    {
        $this->session->set('pairing', ['deviceCode' => 'd3v1c3', 'userCode' => '483291', 'verificationUrl' => 'https://kissj.example/pair', 'expiresAt' => '2027-05-21T09:10:00+02:00']);
        $pairing = $this->pairing->pending();
        self::assertNotNull($pairing);
        self::assertSame('d3v1c3', $pairing->deviceCode);
        self::assertSame('483 291', $pairing->formattedUserCode());
        self::assertSame('https://kissj.example/pair', $pairing->verificationUrl);
        self::assertSame('2027-05-21T09:10:00+02:00', $pairing->expiresAt->format(\DATE_ATOM));
    }

    public function testAnApprovalDropsThePairingAndAnyOutcome(): void
    {
        $this->pairing->begin(new Pairing('d3v1c3', '483291', 'https://kissj.example/pair', new \DateTimeImmutable('2027-05-21T09:10:00+02:00'), 5));
        $this->session->set('pairingOutcome', ['code' => 'gone', 'at' => $this->clock->now()->getTimestamp()]);
        $this->pairing->approved();
        self::assertFalse($this->session->has('pairing'));
        self::assertFalse($this->session->has('pairingOutcome'));
    }

    public function testALogoutDropsThePairingOnly(): void
    {
        $this->pairing->begin(new Pairing('d3v1c3', '483291', 'https://kissj.example/pair', new \DateTimeImmutable('2027-05-21T09:10:00+02:00'), 5));
        $this->session->set('pairingOutcome', ['code' => 'gone', 'at' => $this->clock->now()->getTimestamp()]);
        $this->pairing->drop();
        self::assertNull($this->pairing->pending());
        self::assertSame('Propojení s KissJ už neplatí, přihlas se znovu.', $this->pairing->outcomeLine());
    }

    public function testAnUnknownCodeShowsNoLine(): void
    {
        $this->session->set('pairingOutcome', ['code' => 'nonsense', 'at' => $this->clock->now()->getTimestamp()]);
        self::assertNull($this->pairing->outcomeLine());
    }

    public function testACheckChangesTheWaitingLineForAMinute(): void
    {
        $start = $this->clock->now();
        self::assertSame(PairingState::WAITING, $this->pairing->waitingLine(), 'before any check');

        $this->pairing->checked();
        self::assertSame(PairingState::NOT_YET, $this->pairing->waitingLine());
        $this->clock->set($start->modify('+59 seconds'));
        self::assertSame(PairingState::NOT_YET, $this->pairing->waitingLine());
        $this->clock->set($start->modify('+60 seconds'));
        self::assertSame(PairingState::WAITING, $this->pairing->waitingLine());
    }

    public function testTheWaitingLinesAreThese(): void
    {
        self::assertSame('Čekám na potvrzení v KissJ…', PairingState::WAITING);
        self::assertSame('Zatím nepotvrzeno, zkus to za chvilku.', PairingState::NOT_YET);
    }

    public function testTheCheckIsStoredAsATimestampAndClearedByBegin(): void
    {
        $this->pairing->checked();
        // 2027-05-21T09:00:00+02:00, the fixed clock's time
        self::assertSame(1810882800, $this->session->get('pairingChecked'));

        $this->pairing->begin(new Pairing('d3v1c3', '483291', 'https://kissj.example/pair', new \DateTimeImmutable('2027-05-21T09:10:00+02:00'), 5));
        self::assertFalse($this->session->has('pairingChecked'));
    }

    public function testAFailureAnApprovalAndALogoutClearTheCheckToo(): void
    {
        foreach (['fail' => fn() => $this->pairing->fail('expired'), 'approved' => fn() => $this->pairing->approved(), 'drop' => fn() => $this->pairing->drop()] as $what => $end) {
            $this->pairing->checked();
            $end();
            self::assertFalse($this->session->has('pairingChecked'), $what);
        }
    }
}
