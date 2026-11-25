<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\PairingState;
use App\Program\Pairing;
use App\Program\Programme;
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

    public function testTheReturnTargetIsKeptUntilTheApproval(): void
    {
        $programme = Programme::fromArray(['id' => 12, 'name' => 'X', 'section' => ['id' => 3]]);
        self::assertNotNull($programme);
        $this->pairing->begin(self::aPairing(), $programme);

        self::assertSame(['program' => 12, 'section' => 3], $this->session->get('pairingReturn'));
        self::assertSame('programy#section-3-program-12', $this->pairing->returnTarget());
        self::assertSame(12, $this->pairing->returnProgram());

        $this->pairing->approved();
        self::assertNull($this->pairing->returnTarget());
        self::assertNull($this->pairing->returnProgram());
        self::assertFalse($this->session->has('pairingReturn'));
    }

    public function testEveryEndDropsTheReturnTarget(): void
    {
        $programme = Programme::fromArray(['id' => 12, 'name' => 'X', 'section' => ['id' => 3]]);
        self::assertNotNull($programme);
        $ends = [
            'fail' => fn() => $this->pairing->fail('expired'),
            'drop' => fn() => $this->pairing->drop(),
            'a start without a target' => fn() => $this->pairing->begin(self::aPairing()),
        ];
        foreach ($ends as $what => $end) {
            $this->pairing->begin(self::aPairing(), $programme);
            self::assertNotNull($this->pairing->returnTarget(), $what);
            $end();
            self::assertNull($this->pairing->returnTarget(), $what);
            self::assertFalse($this->session->has('pairingReturn'), $what);
        }
    }

    public function testAMalformedReturnTargetIsNone(): void
    {
        foreach (['x', ['program' => '12', 'section' => 3], ['program' => 0, 'section' => 3], ['program' => 12, 'section' => -1], ['program' => 12]] as $stored) {
            $this->session->set('pairingReturn', $stored);
            self::assertNull($this->pairing->returnTarget(), json_encode($stored, JSON_THROW_ON_ERROR));
        }
        // section 0 is a programme without a section, still a sheet
        $this->session->set('pairingReturn', ['program' => 12, 'section' => 0]);
        self::assertSame('programy#section-0-program-12', $this->pairing->returnTarget());
    }

    public function testProgramIdAcceptsOnlyAPositiveDecimal(): void
    {
        self::assertSame(1, PairingState::programId('1'));
        self::assertSame(4294967295, PairingState::programId('4294967295'));
        foreach (['0', '-1', '1#x', ' 1', "1\n", 'abc', '12345678901', '', 5, null] as $value) {
            self::assertNull(PairingState::programId($value), var_export($value, true));
        }
    }

    public function testAFailureAnApprovalAndALogoutClearTheCheckToo(): void
    {
        foreach (['fail' => fn() => $this->pairing->fail('expired'), 'approved' => fn() => $this->pairing->approved(), 'drop' => fn() => $this->pairing->drop()] as $what => $end) {
            $this->pairing->checked();
            $end();
            self::assertFalse($this->session->has('pairingChecked'), $what);
        }
    }

    private static function aPairing(): Pairing
    {
        return new Pairing('d3v1c3', '483291', 'https://kissj.example/pair', new \DateTimeImmutable('2027-05-21T09:10:00+02:00'), 5);
    }
}
