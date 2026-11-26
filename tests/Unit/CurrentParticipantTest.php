<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Authenticator;
use App\Auth\CurrentParticipant;
use App\Auth\Identity;
use App\Auth\PairingState;
use App\Auth\ParticipantRead;
use App\Program\Pairing;
use App\Program\PairingPoll;
use App\Program\Participant;
use App\Program\ProgramProviderInterface;
use App\Program\Programme;
use App\Program\ProgramUnavailableException;
use App\Program\RegistrationWindow;
use App\Program\SessionExpiredException;
use App\Session;
use App\Time\SystemClock;
use PHPUnit\Framework\TestCase;

/** The paired reader's participant read, one case per outcome. */
final class CurrentParticipantTest extends TestCase
{
    private Authenticator $auth;

    private PairingState $pairing;

    protected function setUp(): void
    {
        $_SESSION = [];
        $session = new Session();
        $this->pairing = new PairingState($session, new SystemClock());
        $this->auth = new Authenticator($session, $this->pairing);
    }

    /**
     * A provider whose participant read answers participant 1 or throws $failure, and
     * counts its calls; every other method is never asked.
     */
    private static function provider(?\Throwable $failure = null): ProgramProviderInterface
    {
        return new class ($failure) implements ProgramProviderInterface {
            public int $calls = 0;

            public function __construct(private readonly ?\Throwable $failure) {}

            public function getParticipant(string $sessionToken): Participant
            {
                $this->calls++;
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return new Participant(1, 'Jana', [Programme::fromArray(['id' => 7, 'name' => 'Lanové centrum']) ?? throw new \LogicException('not a programme row')]);
            }

            public function getPrograms(): array
            {
                throw new \LogicException('not asked');
            }

            public function getSections(): array
            {
                throw new \LogicException('not asked');
            }

            public function getPlaces(): array
            {
                return [];
            }

            public function getRegistrationWindow(): RegistrationWindow
            {
                throw new \LogicException('not asked');
            }

            public function getParticipantIdsForProgramme(int $programmeId): array
            {
                throw new \LogicException('not asked');
            }

            public function startPairing(string $deviceId): Pairing
            {
                throw new \LogicException('not asked');
            }

            public function pollPairing(string $deviceCode): PairingPoll
            {
                throw new \LogicException('not asked');
            }

            public function register(string $sessionToken, int $programmeId): void
            {
                throw new \LogicException('not asked');
            }

            public function unregister(string $sessionToken, int $programmeId): void
            {
                throw new \LogicException('not asked');
            }

            public function revokeSession(string $sessionToken): void
            {
                throw new \LogicException('not asked');
            }
        };
    }

    private function pair(): void
    {
        $this->auth->store(new Identity(1, 'Jana', 'stub-1-0123456789abcdef'));
    }

    public function testANotPairedReaderIsNoReadAndAsksNothing(): void
    {
        $provider = self::provider();
        $read = new CurrentParticipant($this->auth, $provider)->read();

        self::assertNull($read->participant);
        self::assertFalse($read->gone);
        self::assertFalse($read->unavailable);
        self::assertSame(0, $provider->calls);
        self::assertEquals(ParticipantRead::none(), $read);
    }

    public function testAPairedReaderGetsTheirParticipant(): void
    {
        $this->pair();
        $read = new CurrentParticipant($this->auth, self::provider())->read();

        self::assertNotNull($read->participant);
        self::assertSame(1, $read->participant->id);
        self::assertEquals([Programme::fromArray(['id' => 7, 'name' => 'Lanové centrum'])], $read->participant->programmes);
        self::assertFalse($read->gone);
        self::assertFalse($read->unavailable);
        self::assertTrue($this->auth->isLogged());
        self::assertEquals(ParticipantRead::read($read->participant), $read);
    }

    public function testASessionKissjNoLongerKnowsLogsTheReaderOut(): void
    {
        $this->pair();
        $read = new CurrentParticipant($this->auth, self::provider(new SessionExpiredException()))->read();

        self::assertNull($read->participant);
        self::assertTrue($read->gone);
        self::assertFalse($read->unavailable);
        self::assertFalse($this->auth->isLogged());
        self::assertSame(PairingState::LINES['gone'], $this->pairing->outcomeLine());
        self::assertEquals(ParticipantRead::gone(), $read);
    }

    public function testAnOutageKeepsTheReaderLoggedIn(): void
    {
        $this->pair();
        $read = new CurrentParticipant($this->auth, self::provider(new ProgramUnavailableException('down')))->read();

        self::assertNull($read->participant);
        self::assertFalse($read->gone);
        self::assertTrue($read->unavailable);
        self::assertTrue($this->auth->isLogged());
        self::assertNull($this->pairing->outcomeLine());
        self::assertEquals(ParticipantRead::unavailable(), $read);
    }
}
