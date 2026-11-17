<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Program\PairingStatus;
use App\Program\RegistrationRefusedException;
use App\Program\SessionExpiredException;
use App\Program\StubProgramProvider;
use PHPUnit\Framework\TestCase;

final class StubProgramProviderTest extends TestCase
{
    /** korbo26's fixture participant 1: real registrations only, none of the preregistered 3, 36 and 51 */
    private const array KORBO_MINE = [5, 10, 26, 31, 45, 50];

    private string $stateDir;

    private StubProgramProvider $provider;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/stub-state-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir);
        $this->provider = new StubProgramProvider(self::fixtures('obrok19'), $this->stateDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->stateDir . '/*') ?: []);
        @rmdir($this->stateDir);
    }

    private static function fixtures(string $slug): string
    {
        return dirname(__DIR__, 2) . '/events/' . $slug . '/fixtures';
    }

    private function korbo(): StubProgramProvider
    {
        return new StubProgramProvider(self::fixtures('korbo26'), $this->stateDir);
    }

    /** A session token of participant 1, the way the app gets one: a pairing. */
    private static function pair(StubProgramProvider $provider): string
    {
        $poll = $provider->pollPairing($provider->startPairing(str_repeat('a', 64))->deviceCode);
        self::assertSame(PairingStatus::Approved, $poll->status);

        return (string) $poll->sessionToken;
    }

    private static function refusal(\Closure $call): string
    {
        try {
            $call();
        } catch (RegistrationRefusedException $e) {
            self::assertSame(sprintf('kissj refused the registration: %s', $e->refusal), $e->getMessage());

            return $e->refusal;
        }
        self::fail('the call should have been refused');
    }

    public function testGetPrograms(): void
    {
        $programs = $this->provider->getPrograms();

        self::assertNotEmpty($programs);
        self::assertArrayHasKey('name', $programs[0]);
        self::assertArrayHasKey('id', $programs[0]['section']);
    }

    /** The fixture is the kissj list's own `sections`, so the stub maps it the same way. */
    public function testGetSectionsIsKeyedByIdInFixtureOrder(): void
    {
        $sections = $this->provider->getSections();

        self::assertSame([10, 12, 13, 1, 2, 11, 14, 15, 16, 3, 4, 17, 18], array_keys($sections));
        self::assertSame(['id' => 10, 'title' => 'Putování', 'subTitle' => null], $sections[10]);
        self::assertSame('1. blok', $sections[3]['subTitle']);
    }

    public function testNoSectionsFixtureMeansNoSections(): void
    {
        $provider = new StubProgramProvider(sys_get_temp_dir() . '/no-such-fixtures-' . bin2hex(random_bytes(4)), $this->stateDir);

        self::assertSame([], $provider->getSections());
    }

    public function testEveryProgrammeSaysWhetherItIsPreregistered(): void
    {
        $preregistered = [];
        foreach ($this->korbo()->getPrograms() as $programme) {
            self::assertIsBool($programme['preregistered']);
            if ($programme['preregistered']) {
                $preregistered[] = $programme['id'];
            }
        }

        self::assertSame([3, 36, 51], $preregistered);
        self::assertNotContains(true, array_column($this->provider->getPrograms(), 'preregistered'), 'a fixture without the key is false');
    }

    public function testTheRegistrationWindowComesFromTheFixture(): void
    {
        $window = $this->korbo()->getRegistrationWindow();
        self::assertEquals(new \DateTimeImmutable('2020-01-01T00:00:00+01:00'), $window->start);
        self::assertEquals(new \DateTimeImmutable('2099-01-01T00:00:00+01:00'), $window->end);

        $obrok27 = (new StubProgramProvider(self::fixtures('obrok27'), $this->stateDir))->getRegistrationWindow();
        self::assertSame('2027-03-15T18:00:00+01:00', $obrok27->start?->format(DATE_ATOM));
        self::assertSame('2027-05-19T00:00:00+02:00', $obrok27->end?->format(DATE_ATOM));

        // no registration.json: no window
        self::assertSame('none', $this->provider->getRegistrationWindow()->state(new \DateTimeImmutable()));
    }

    public function testAPairingStartsWithTheContractsShape(): void
    {
        $deviceId = hash('sha256', 'session-id');
        $before = new \DateTimeImmutable();

        $pairing = $this->korbo()->startPairing($deviceId);

        self::assertSame(substr(hash('sha256', 'pairing:' . $deviceId), 0, 32), $pairing->deviceCode);
        self::assertSame('483291', $pairing->userCode);
        self::assertSame('483-291', $pairing->formattedUserCode());
        self::assertSame('https://kissj.example/pairing', $pairing->verificationUrl);
        self::assertSame(5, $pairing->interval);
        self::assertGreaterThanOrEqual($before->modify('+1 hour')->getTimestamp(), $pairing->expiresAt->getTimestamp());
        self::assertLessThanOrEqual((new \DateTimeImmutable('+1 hour'))->getTimestamp(), $pairing->expiresAt->getTimestamp());
    }

    public function testThePairingIsApprovedAtOnceWithAFreshTokenEveryTime(): void
    {
        $provider = $this->korbo();
        $deviceCode = $provider->startPairing(str_repeat('a', 64))->deviceCode;

        $first = $provider->pollPairing($deviceCode);
        $second = $provider->pollPairing($deviceCode);

        self::assertSame(PairingStatus::Approved, $first->status);
        self::assertMatchesRegularExpression('/^stub-1-[0-9a-f]{16}$/', (string) $first->sessionToken);
        self::assertMatchesRegularExpression('/^stub-1-[0-9a-f]{16}$/', (string) $second->sessionToken);
        self::assertNotSame($first->sessionToken, $second->sessionToken);
        self::assertGreaterThan(new \DateTimeImmutable('+5 months'), $first->sessionExpiresAt);
    }

    public function testADeviceCodeThatIsNotOneHasExpired(): void
    {
        $poll = $this->korbo()->pollPairing('not-a-device-code');

        self::assertSame(PairingStatus::Expired, $poll->status);
        self::assertNull($poll->sessionToken);
    }

    /** The fixture events have no registered.json: participant 1, with nothing registered. */
    public function testAnEventWithoutRegistrationsPairsParticipantOneWithNoProgrammes(): void
    {
        $provider = new StubProgramProvider(dirname(__DIR__) . '/fixtures/events/programs/fixtures', $this->stateDir);

        $participant = $provider->getParticipant(self::pair($provider));

        self::assertSame(1, $participant->id);
        self::assertNull($participant->nickname);
        self::assertSame([], $participant->programmes);
    }

    public function testTheParticipantHasTheirProgrammesAndNickname(): void
    {
        $provider = $this->korbo();

        $participant = $provider->getParticipant(self::pair($provider));

        self::assertSame(1, $participant->id);
        self::assertSame('Jana Nováková', $participant->nickname);
        self::assertSame(self::KORBO_MINE, array_column($participant->programmes, 'id'));
        self::assertSame('Volejbal', $provider->getPrograms()[0]['name'], 'the programmes are in the internal shape');
        self::assertArrayHasKey('preregistered', $participant->programmes[0]);
    }

    public function testAnEventWithoutNicknamesHasNone(): void
    {
        $participant = $this->provider->getParticipant(self::pair($this->provider));

        self::assertSame(1, $participant->id);
        self::assertNull($participant->nickname);
        $ids = array_column($participant->programmes, 'id');
        sort($ids);
        self::assertSame([5, 22, 29, 34, 49, 62, 73, 84], $ids);
    }

    /** @return iterable<string, array{string}> */
    public static function notASession(): iterable
    {
        yield 'empty' => [''];
        yield 'a kissj-looking token' => [str_repeat('ab', 32)];
        yield 'a short random part' => ['stub-1-0123456789abcde'];
        yield 'an upper-case random part' => ['stub-1-0123456789ABCDEF'];
        yield 'a participant nobody registered' => ['stub-2-0123456789abcdef'];
    }

    /** The message never carries the token: messages reach logs and Sentry. */
    public function testATokenThatIsNoSessionHasExpired(): void
    {
        foreach (self::notASession() as $name => [$token]) {
            try {
                $this->korbo()->getParticipant($token);
                self::fail($name . ' should not be a session');
            } catch (SessionExpiredException $e) {
                self::assertSame('Device session is no longer valid', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }

    public function testARevokedSessionHasExpiredAndTheNextPairingWorks(): void
    {
        $provider = $this->korbo();
        $token = self::pair($provider);

        $provider->revokeSession($token);

        try {
            $provider->getParticipant($token);
            self::fail('a revoked session must not read');
        } catch (SessionExpiredException) {
        }
        try {
            (new StubProgramProvider(self::fixtures('korbo26'), $this->stateDir))->getParticipant($token);
            self::fail('the revocation is kept across requests');
        } catch (SessionExpiredException) {
        }
        self::assertSame(1, $provider->getParticipant(self::pair($provider))->id, 'the same participant pairs again');
    }

    public function testRevokingASessionThatIsNoneHasExpired(): void
    {
        $this->expectException(SessionExpiredException::class);

        $this->korbo()->revokeSession('stub-2-0123456789abcdef');
    }

    public function testARegistrationIsKeptForTheNextRequest(): void
    {
        $provider = $this->korbo();
        $token = self::pair($provider);

        $provider->register($token, 1);

        self::assertSame([1, ...self::KORBO_MINE], array_column($provider->getParticipant($token)->programmes, 'id'));
        $next = new StubProgramProvider(self::fixtures('korbo26'), $this->stateDir);
        self::assertSame([1, ...self::KORBO_MINE], array_column($next->getParticipant($token)->programmes, 'id'));
        self::assertSame(
            ['registrations' => ['1' => [...self::KORBO_MINE, 1]], 'revoked' => []],
            json_decode((string) file_get_contents($this->stateDir . '/korbo26.json'), true),
            'the state file is <stateDir>/<slug>.json',
        );
    }

    public function testAnUnregistrationIsKeptForTheNextRequest(): void
    {
        $provider = $this->korbo();
        $token = self::pair($provider);

        $provider->unregister($token, 5);

        $next = new StubProgramProvider(self::fixtures('korbo26'), $this->stateDir);
        self::assertSame([10, 26, 31, 45, 50], array_column($next->getParticipant($token)->programmes, 'id'));
    }

    public function testTheRefusalsInTheContractsOrder(): void
    {
        $provider = $this->korbo();
        $token = self::pair($provider);

        self::assertSame('alreadyRegistered', self::refusal(fn () => $provider->register($token, 5)));
        self::assertSame('notRegistered', self::refusal(fn () => $provider->unregister($token, 1)));
        self::assertSame('notFound', self::refusal(fn () => $provider->register($token, 9999)));
        self::assertSame('notFound', self::refusal(fn () => $provider->unregister($token, 9999)));
        // preregistered: 51 is nobody's to take, 3 nobody's to leave
        self::assertSame('notPossible', self::refusal(fn () => $provider->register($token, 51)));
        self::assertSame('notPossible', self::refusal(fn () => $provider->unregister($token, 3)));
        // every check is made under the state file's lock, which may create it empty
        self::assertSame('', (string) @file_get_contents($this->stateDir . '/korbo26.json'), 'a refusal writes nothing');
    }

    /**
     * As kissj: a preregistered programme belongs to a role and holds no registrations, so
     * it is in nobody's list — even when a hand-edited registered.json names it.
     */
    public function testAPreregisteredProgrammeIsNeverRegistered(): void
    {
        $dir = $this->stateDir . '/fixtures-' . bin2hex(random_bytes(4));
        mkdir($dir . '/fixtures', recursive: true);
        file_put_contents($dir . '/fixtures/registered.json', json_encode(['1' => [3, 5]]));
        file_put_contents($dir . '/fixtures/programs.json', json_encode([['id' => 3, 'preregistered' => true], ['id' => 5]]));
        $provider = new StubProgramProvider($dir . '/fixtures', $this->stateDir);

        try {
            self::assertSame([5], array_column($provider->getParticipant('stub-1-0123456789abcdef')->programmes, 'id'));
            self::assertSame([], $provider->getParticipantIdsForProgramme(3));
            self::assertSame([1], $provider->getParticipantIdsForProgramme(5));
        } finally {
            array_map('unlink', glob($dir . '/fixtures/*') ?: []);
            rmdir($dir . '/fixtures');
            rmdir($dir);
        }
    }

    /** No shipped fixture lists a preregistered programme as somebody's, as no kissj answer does. */
    public function testNoFixtureRegistersAPreregisteredProgramme(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__, 2) . '/events/*/fixtures/registered.json') ?: [] as $path) {
            $preregistered = array_column(array_filter(
                json_decode((string) file_get_contents(dirname($path) . '/programs.json'), true),
                static fn (array $programme): bool => ($programme['preregistered'] ?? false) === true,
            ), 'id');
            foreach (json_decode((string) file_get_contents($path), true) as $participant => $ids) {
                $checked++;
                self::assertSame([], array_values(array_intersect($ids, $preregistered)), $path . ' participant ' . $participant);
            }
        }
        foreach (glob(dirname(__DIR__) . '/fixtures/kissj/*/participant.json') ?: [] as $path) {
            $checked++;
            self::assertNotContains(true, array_column(json_decode((string) file_get_contents($path), true)['programmes'], 'isPreregistered'), $path);
        }
        self::assertGreaterThan(5, $checked);
    }

    /**
     * A state directory that cannot be made is an outage, not a crash: a TransferException,
     * which the Program screen's write turns into "zkus to prosím později".
     */
    public function testAnUnwritableStateDirectoryIsAnOutage(): void
    {
        // a plain file where the directory should be: no mkdir under it, even for root
        $file = $this->stateDir . '/not-a-directory';
        touch($file);
        $provider = new StubProgramProvider(self::fixtures('korbo26'), $file . '/state');
        $token = self::pair($provider);

        try {
            $provider->register($token, 1);
            self::fail('the write cannot have landed');
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            self::assertInstanceOf(\App\Program\LocalStateException::class, $e);
        }
    }

    public function testOutsideTheWindowEveryWriteIsClosed(): void
    {
        // obrok19 has no registration.json
        $token = self::pair($this->provider);

        self::assertSame('closed', self::refusal(fn () => $this->provider->register($token, 1)));
        self::assertSame('closed', self::refusal(fn () => $this->provider->unregister($token, 5)));
    }

    public function testAWriteWithAnExpiredSessionIsNoRefusal(): void
    {
        $this->expectException(SessionExpiredException::class);

        $this->korbo()->register('stub-2-0123456789abcdef', 1);
    }

    /** Two workers write the file at once; a reader that catches it half-written sees no state. */
    public function testAStateFileThatIsNotJsonCountsAsEmpty(): void
    {
        file_put_contents($this->stateDir . '/korbo26.json', '{');
        $provider = $this->korbo();
        $token = self::pair($provider);

        self::assertSame(self::KORBO_MINE, array_column($provider->getParticipant($token)->programmes, 'id'));

        $provider->register($token, 1);
        self::assertSame([1, ...self::KORBO_MINE], array_column($this->korbo()->getParticipant($token)->programmes, 'id'));
    }

    public function testTheStateDirectoryIsCreatedOnTheFirstWrite(): void
    {
        $dir = $this->stateDir . '/nested';
        $provider = new StubProgramProvider(self::fixtures('korbo26'), $dir);
        $token = self::pair($provider);

        try {
            $provider->register($token, 1);
            self::assertFileExists($dir . '/korbo26.json');
        } finally {
            @unlink($dir . '/korbo26.json');
            @rmdir($dir);
        }
    }

    public function testParticipantIdsForAProgrammeAreTheRegistrationsInverted(): void
    {
        $dir = $this->stateDir . '/fixtures-' . bin2hex(random_bytes(4));
        mkdir($dir . '/fixtures', recursive: true);
        file_put_contents($dir . '/fixtures/registered.json', json_encode(['1' => [3, 5], '2' => [5], '3' => []]));
        file_put_contents($dir . '/fixtures/programs.json', json_encode([['id' => 3], ['id' => 5], ['id' => 7]]));
        file_put_contents($dir . '/fixtures/registration.json', json_encode(['start' => '2020-01-01T00:00:00+01:00', 'end' => '2099-01-01T00:00:00+01:00']));
        $provider = new StubProgramProvider($dir . '/fixtures', $this->stateDir);

        try {
            self::assertSame([1, 2], $provider->getParticipantIdsForProgramme(5));
            self::assertSame([1], $provider->getParticipantIdsForProgramme(3));
            self::assertSame([], $provider->getParticipantIdsForProgramme(99));

            // a registration made in the app counts too
            $provider->getParticipant('stub-3-0123456789abcdef');
            $provider->register('stub-3-0123456789abcdef', 7);
            self::assertSame([3], $provider->getParticipantIdsForProgramme(7));
        } finally {
            array_map('unlink', glob($dir . '/fixtures/*') ?: []);
            rmdir($dir . '/fixtures');
            rmdir($dir);
        }
    }
}
