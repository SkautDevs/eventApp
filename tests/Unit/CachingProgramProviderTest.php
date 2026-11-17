<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cache\FileCache;
use App\Program\CachingProgramProvider;
use App\Program\Freshness;
use App\Program\Pairing;
use App\Program\PairingPoll;
use App\Program\PairingStatus;
use App\Program\Participant;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\RegistrationRefusedException;
use App\Program\RegistrationWindow;
use App\Program\SessionExpiredException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class CachingProgramProviderTest extends TestCase
{
    private const array SECTIONS = [
        10 => ['id' => 10, 'title' => 'Putování', 'subTitle' => null],
        1 => ['id' => 1, 'title' => 'Hlavní program', 'subTitle' => null],
    ];

    private const array PROGRAMMES = [[
        'id' => 5, 'name' => 'Ukázková vycházka', 'section' => ['id' => 10],
        'start' => ['date' => '2027-06-03 08:00:00'], 'end' => ['date' => '2027-06-03 12:00:00'],
        'lector' => null, 'location' => 'Sraz u brány', 'perex' => null, 'tools' => null,
    ]];

    private const array OLD_PROGRAMMES = [[
        'id' => 5, 'name' => 'Včerejší vycházka', 'section' => ['id' => 10],
        'start' => ['date' => '2027-06-03 08:00:00'], 'end' => ['date' => '2027-06-03 12:00:00'],
        'lector' => null, 'location' => 'Sraz u brány', 'perex' => null, 'tools' => null,
    ]];

    private const array REGISTRATION = ['start' => '2027-03-15T18:00:00+01:00', 'end' => '2027-05-19T00:00:00+02:00'];

    /** the device session token; the cache must never write it anywhere */
    private const string TOKEN = 'stub-1-0123456789abcdef';

    private string $dir;

    private \DateTimeImmutable $now;

    /** @var list<\Throwable> what reached the collector */
    private array $reported = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/caching-provider-' . bin2hex(random_bytes(4));
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('Europe/Prague'));
        $this->reported = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    private function clock(): \Closure
    {
        return fn (): \DateTimeImmutable => $this->now;
    }

    private function cache(): FileCache
    {
        return new FileCache($this->dir, $this->clock());
    }

    private function provider(FakeProgramProvider $inner, ?Freshness $freshness = null, int $ttl = 300, ?FileCache $cache = null, float $lockWait = 12.0): CachingProgramProvider
    {
        return new CachingProgramProvider(
            inner: $inner,
            cache: $cache ?? $this->cache(),
            freshness: $freshness ?? new Freshness($this->now),
            ttl: $ttl,
            now: $this->clock(),
            report: function (\Throwable $e): void {
                $this->reported[] = $e;
            },
            lockWait: $lockWait,
        );
    }

    /** An entry as it would have been written $age seconds before "now". */
    private function seed(string $key, mixed $data, int $age): void
    {
        $then = $this->now->modify(sprintf('-%d seconds', $age));
        (new FileCache($this->dir, static fn (): \DateTimeImmutable => $then))->set($key, $data);
    }

    /** A list entry as the decorator writes it. */
    private static function listEntry(array $programmes): array
    {
        return ['sections' => self::SECTIONS, 'programmes' => $programmes, 'registration' => self::REGISTRATION];
    }

    /** A session entry as the decorator writes it. */
    private static function sessionEntry(array $programmes): array
    {
        return ['id' => 1, 'nickname' => 'Jana', 'programmes' => $programmes];
    }

    /** @return list<array> the programmes of the participant behind TOKEN, through $provider */
    private static function mine(CachingProgramProvider $provider): array
    {
        return $provider->getParticipant(self::TOKEN)->programmes;
    }

    private static function down(): ConnectException
    {
        return new ConnectException('down', new Request('GET', 'v3/programme/list'));
    }

    public function testAFreshEntryMakesNoInnerCall(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 299);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);

        $provider = $this->provider($inner);

        self::assertSame(self::OLD_PROGRAMMES, $provider->getPrograms());
        self::assertSame(self::SECTIONS, $provider->getSections());
        self::assertSame(0, $inner->listCalls);
    }

    public function testAMissingEntryIsFetchedOnceForBothGettersAndWritten(): void
    {
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $provider = $this->provider($inner);

        self::assertSame(self::PROGRAMMES, $provider->getPrograms());
        self::assertSame(self::SECTIONS, $provider->getSections());

        // one fetch is getSections() + getPrograms() + getRegistrationWindow() on the inner provider
        self::assertSame(2, $inner->listCalls);
        self::assertSame(1, $inner->windowCalls);
        $entry = $this->cache()->get(CachingProgramProvider::LIST_KEY);
        self::assertSame(self::listEntry(self::PROGRAMMES), $entry->data);
        self::assertSame($this->now->format(\DATE_ATOM), $entry->fetchedAt->format(\DATE_ATOM));
    }

    public function testAnExpiredEntryIsRefetchedAndRewritten(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 301);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);

        self::assertSame(self::PROGRAMMES, $this->provider($inner)->getPrograms());

        self::assertSame(2, $inner->listCalls);
        self::assertSame(self::PROGRAMMES, $this->cache()->get(CachingProgramProvider::LIST_KEY)->data['programmes']);
        self::assertSame([], $this->reported);
    }

    public function testAnExpiredEntryIsServedWhenKissjCannotBeReached(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 86400 * 2);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = self::down();

        $provider = $this->provider($inner);

        // however old: Tuesday's schedule beats an empty grid on Thursday
        self::assertSame(self::OLD_PROGRAMMES, $provider->getPrograms());
        self::assertSame(self::SECTIONS, $provider->getSections());
        self::assertCount(1, $this->reported);
        self::assertSame($inner->failure, $this->reported[0]);
    }

    public function testAMalformedAnswerAlsoFallsBackToTheStaleEntry(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 301);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = new ProgramDataException('kissj sent no list of programmes for v3/programme/list');

        self::assertSame(self::OLD_PROGRAMMES, $this->provider($inner)->getPrograms());
        self::assertCount(1, $this->reported);
        self::assertInstanceOf(ProgramDataException::class, $this->reported[0]);
    }

    public function testWithoutAnEntryAFailureGoesThroughUnchanged(): void
    {
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = self::down();

        try {
            $this->provider($inner)->getPrograms();
            self::fail('the failure was swallowed');
        } catch (ConnectException $e) {
            self::assertSame($inner->failure, $e);
        }
        self::assertSame([], $this->reported, 'the module reports nothing either: it shows its notice');
    }

    /** The list entry carries the window, so the window costs no request of its own. */
    public function testTheWindowComesFromTheListEntry(): void
    {
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $provider = $this->provider($inner);

        $provider->getPrograms();
        $window = $provider->getRegistrationWindow();

        self::assertSame(self::REGISTRATION['start'], $window->start?->format(\DATE_ATOM));
        self::assertSame(self::REGISTRATION['end'], $window->end?->format(\DATE_ATOM));
        self::assertSame(2, $inner->listCalls);
        self::assertSame(1, $inner->windowCalls, 'no second fetch for the window');
    }

    public function testAFreshListEntryAnswersTheWindowWithoutAsking(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => [], 'registration' => ['start' => null, 'end' => '2027-05-19T00:00:00+02:00']], 10);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);

        $window = $this->provider($inner)->getRegistrationWindow();

        self::assertNull($window->start);
        self::assertSame('2027-05-19T00:00:00+02:00', $window->end?->format(\DATE_ATOM));
        self::assertSame('Europe/Prague', $window->end?->getTimezone()->getName());
        self::assertSame(0, $inner->listCalls + $inner->windowCalls);
    }

    public function testParticipantIdsForAProgrammeAreNeverCached(): void
    {
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $provider = $this->provider($inner);

        self::assertSame([1], $provider->getParticipantIdsForProgramme(5));
        self::assertSame([1], $provider->getParticipantIdsForProgramme(5));

        self::assertSame(2, $inner->participantIdCalls);
        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    /** A pairing is a conversation with kissj, never an entry: every call reaches it. */
    public function testPairingAndRevokingPassThroughUncached(): void
    {
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $provider = $this->provider($inner);

        self::assertSame('483291', $provider->startPairing(str_repeat('ab', 32))->userCode);
        self::assertSame('483291', $provider->startPairing(str_repeat('ab', 32))->userCode);
        self::assertSame(PairingStatus::Approved, $provider->pollPairing('0123456789abcdef0123456789abcdef')->status);
        self::assertSame(PairingStatus::Approved, $provider->pollPairing('0123456789abcdef0123456789abcdef')->status);
        $provider->revokeSession(self::TOKEN);

        self::assertSame(['start' => 2, 'poll' => 2, 'revoke' => 1], [
            'start' => $inner->startCalls,
            'poll' => $inner->pollCalls,
            'revoke' => $inner->revokeCalls,
        ]);
        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testAnExpiredSessionDeletesTheEntryAndRethrows(): void
    {
        $key = CachingProgramProvider::sessionKey(self::TOKEN);
        $this->seed($key, self::sessionEntry(self::OLD_PROGRAMMES), 301);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->identityFailure = new SessionExpiredException();

        try {
            $this->provider($inner)->getParticipant(self::TOKEN);
            self::fail('a session kissj no longer knows must not stay logged in from the cache');
        } catch (SessionExpiredException $e) {
            self::assertSame($inner->identityFailure, $e);
        }
        self::assertNull($this->cache()->get($key));
        self::assertSame([], $this->reported, 'an answer, not an outage');
    }

    public function testTheSessionEntryIsNamedByAHashAndHoldsTheParticipant(): void
    {
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);

        $participant = $this->provider($inner)->getParticipant(self::TOKEN);
        self::assertSame([1, 'Jana', self::PROGRAMMES], [$participant->id, $participant->nickname, $participant->programmes]);

        $expected = 'session-' . substr(hash('sha256', self::TOKEN), 0, 32);
        self::assertSame($expected, CachingProgramProvider::sessionKey(self::TOKEN));
        // the entry and the lock that serialises fetching it: both named by the hash
        self::assertSame([$expected . '.json', $expected . '.lock'], array_map('basename', glob($this->dir . '/*') ?: []));
        self::assertStringNotContainsString(self::TOKEN, (string) file_get_contents($this->dir . '/' . $expected . '.json'));
        self::assertSame(self::sessionEntry(self::PROGRAMMES), $this->cache()->get($expected)->data);
    }

    public function testAFreshSessionEntryIsServedWithoutAsking(): void
    {
        $this->seed(CachingProgramProvider::sessionKey(self::TOKEN), ['id' => 4711, 'nickname' => null, 'programmes' => self::OLD_PROGRAMMES], 299);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);

        $participant = $this->provider($inner)->getParticipant(self::TOKEN);

        self::assertSame([4711, null, self::OLD_PROGRAMMES], [$participant->id, $participant->nickname, $participant->programmes]);
        self::assertSame(0, $inner->identityCalls);
    }

    /**
     * Review Focus: the redirect after a register or unregister reads the participant
     * again, in the same request or the next one, and must see the change — so a success
     * drops both the entry and this request's memo.
     */
    public function testASuccessfulWriteMakesTheNextReadFresh(): void
    {
        foreach (['register', 'unregister'] as $verb) {
            $this->tearDown();
            $key = CachingProgramProvider::sessionKey(self::TOKEN);
            $this->seed($key, self::sessionEntry(self::OLD_PROGRAMMES), 10);
            $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
            $provider = $this->provider($inner);

            self::assertSame(self::OLD_PROGRAMMES, self::mine($provider), $verb);
            self::assertSame(0, $inner->identityCalls, $verb);

            $provider->$verb(self::TOKEN, 5);
            self::assertSame([[self::TOKEN, 5]], $inner->writes[$verb], $verb);
            self::assertNull($this->cache()->get($key), $verb . ': the entry is gone');

            // the same request, so the memo would have answered
            self::assertSame(self::PROGRAMMES, self::mine($provider), $verb);
            self::assertSame(1, $inner->identityCalls, $verb . ': the inner provider was asked');
        }
    }

    public function testARefusedWriteKeepsTheEntry(): void
    {
        foreach (['register', 'unregister'] as $verb) {
            $this->tearDown();
            $key = CachingProgramProvider::sessionKey(self::TOKEN);
            $this->seed($key, self::sessionEntry(self::OLD_PROGRAMMES), 10);
            $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
            $inner->writeFailure = new RegistrationRefusedException('full');
            $provider = $this->provider($inner);
            self::mine($provider);

            try {
                $provider->$verb(self::TOKEN, 5);
                self::fail($verb . ': the refusal was swallowed');
            } catch (RegistrationRefusedException $e) {
                self::assertSame($inner->writeFailure, $e, $verb);
            }

            self::assertNotNull($this->cache()->get($key), $verb . ': nothing changed at kissj');
            self::assertSame(self::OLD_PROGRAMMES, self::mine($provider), $verb);
            self::assertSame(0, $inner->identityCalls, $verb);
        }
    }

    /**
     * kissj refusing a register as alreadyRegistered, or an unregister as notRegistered,
     * says the cached participant is not what kissj holds: the entry and the memo go, so
     * the redirect shows kissj's state. Every other refusal keeps both.
     */
    public function testARefusalThatProvesTheEntryStaleForgetsIt(): void
    {
        foreach (['register' => 'alreadyRegistered', 'unregister' => 'notRegistered'] as $verb => $code) {
            $this->tearDown();
            $key = CachingProgramProvider::sessionKey(self::TOKEN);
            $this->seed($key, self::sessionEntry(self::OLD_PROGRAMMES), 10);
            $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
            $inner->writeFailure = new RegistrationRefusedException($code);
            $provider = $this->provider($inner);
            self::assertSame(self::OLD_PROGRAMMES, self::mine($provider), $verb);

            try {
                $provider->$verb(self::TOKEN, 5);
                self::fail($verb . ': the refusal was swallowed');
            } catch (RegistrationRefusedException $e) {
                self::assertSame($inner->writeFailure, $e, $verb);
            }

            self::assertNull($this->cache()->get($key), $verb . ': the entry is gone');
            self::assertSame(self::PROGRAMMES, self::mine($provider), $verb . ': the memo is gone too');
            self::assertSame(1, $inner->identityCalls, $verb);
        }
    }

    /**
     * notFound, closed and notPossible say the programme list is not what kissj holds: the
     * programme is gone, the window moved, or the programme became preregistered. The list
     * entry and its memo go, so the redirect's read refetches the list and the window. Any
     * other refusal keeps the list.
     */
    public function testARefusalThatProvesTheListStaleForgetsIt(): void
    {
        foreach (['notFound' => true, 'closed' => true, 'notPossible' => true, 'full' => false, 'alreadyRegistered' => false] as $code => $forgets) {
            $this->tearDown();
            $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 10);
            $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
            $inner->writeFailure = new RegistrationRefusedException($code);
            $provider = $this->provider($inner);
            self::assertSame(self::OLD_PROGRAMMES, $provider->getPrograms(), $code);

            try {
                $provider->register(self::TOKEN, 5);
                self::fail($code . ': the refusal was swallowed');
            } catch (RegistrationRefusedException $e) {
                self::assertSame($inner->writeFailure, $e, $code);
            }

            if ($forgets) {
                self::assertNull($this->cache()->get(CachingProgramProvider::LIST_KEY), $code . ': the list entry is gone');
                self::assertSame(self::PROGRAMMES, $provider->getPrograms(), $code . ': the memo is gone too');
                self::assertSame(1, $inner->windowCalls, $code . ': the window was asked again with the list');
            } else {
                self::assertSame(self::OLD_PROGRAMMES, $provider->getPrograms(), $code);
                self::assertSame(0, $inner->listCalls, $code);
            }
        }
    }

    /**
     * Two requests: A reads the participant with nothing cached, and while kissj's answer
     * from before the change is on its way, B's register lands and forgets the entry. A
     * shows what it was told but stores nothing, or the old list would stand for the TTL
     * after the write and the redirect.
     */
    public function testAReadOverlappingAWriteDoesNotStoreItsOlderAnswer(): void
    {
        $key = CachingProgramProvider::sessionKey(self::TOKEN);
        $kissjAfter = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
        // B finds A holding the entry's lock, and goes ahead once its short wait runs out
        $writer = $this->provider($kissjAfter, lockWait: 0.1);
        $kissjBefore = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::OLD_PROGRAMMES);
        $kissjBefore->duringIdentity = static function () use ($writer): void {
            $writer->register(self::TOKEN, 5);
        };

        self::assertSame(self::OLD_PROGRAMMES, self::mine($this->provider($kissjBefore)), 'A shows what kissj told it');
        self::assertSame([[self::TOKEN, 5]], $kissjAfter->writes['register']);
        self::assertNull($this->cache()->get($key), 'A stored an answer from before the write');

        // the next request asks kissj, and gets the present
        self::assertSame(self::PROGRAMMES, self::mine($this->provider($kissjAfter)));
        self::assertNotNull($this->cache()->get($key), 'a read with no write in between stores as before');
    }

    /** A revoke ends the session whatever kissj answers: the entry and the memo go either way. */
    public function testARevokeForgetsTheEntryEvenWhenItFails(): void
    {
        foreach ([null, self::down(), new SessionExpiredException()] as $failure) {
            $this->tearDown();
            $label = $failure === null ? 'success' : $failure::class;
            $key = CachingProgramProvider::sessionKey(self::TOKEN);
            $this->seed($key, self::sessionEntry(self::OLD_PROGRAMMES), 10);
            $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
            $inner->revokeFailure = $failure;
            $provider = $this->provider($inner);
            self::mine($provider);

            try {
                $provider->revokeSession(self::TOKEN);
                self::assertNull($failure, $label . ': the failure was swallowed');
            } catch (\Throwable $e) {
                self::assertSame($failure, $e, $label);
            }

            self::assertNull($this->cache()->get($key), $label . ': the entry is gone');
            self::assertSame(self::PROGRAMMES, self::mine($provider), $label . ': the memo is gone too');
        }
    }

    /** A write that finds the session gone drops its entry, as the read does. */
    public function testAWriteOnAnExpiredSessionDeletesTheEntry(): void
    {
        $key = CachingProgramProvider::sessionKey(self::TOKEN);
        $this->seed($key, self::sessionEntry(self::OLD_PROGRAMMES), 10);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->writeFailure = new SessionExpiredException();

        try {
            $this->provider($inner)->register(self::TOKEN, 5);
            self::fail('the expiry was swallowed');
        } catch (SessionExpiredException $e) {
            self::assertSame($inner->writeFailure, $e);
        }
        self::assertNull($this->cache()->get($key));
    }

    public function testFreshnessCarriesTheOldestFetchAndStaleAfterAMixedRequest(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::PROGRAMMES), 100);
        $this->seed(CachingProgramProvider::sessionKey(self::TOKEN), self::sessionEntry(self::OLD_PROGRAMMES), 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->identityFailure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        $provider->getPrograms();
        self::assertSame(['fetchedAt' => '2026-10-04T11:58:20+02:00', 'stale' => false], $freshness->forView(), 'a fresh hit alone');

        self::mine($provider);
        self::assertSame(['fetchedAt' => '2026-10-04T11:53:20+02:00', 'stale' => true], $freshness->forView(), 'the oldest, and stale');
    }

    public function testTtlZeroRefetchesYetServesStaleOnError(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 0);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);

        // written this very second, and still asked again
        self::assertSame(self::PROGRAMMES, $this->provider($inner, ttl: 0)->getPrograms());
        self::assertSame(2, $inner->listCalls);

        $inner->failure = self::down();
        self::assertSame(self::PROGRAMMES, $this->provider($inner, ttl: 0)->getPrograms(), 'the entry written above covers the outage');
        self::assertCount(1, $this->reported);
    }

    /** Review Focus 1: an outage costs one timeout per request, not one per getter. */
    public function testAFailureIsTriedOncePerRequestNotOncePerGetter(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 301);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        $provider->getPrograms();
        $provider->getSections();
        self::assertSame(1, $inner->listCalls, 'getSections() threw once; getPrograms() was never asked again');

        // the next request tries again once the breaker has closed: recovery is noticed on
        // the first request after it
        $this->now = $this->now->modify(sprintf('+%d seconds', CachingProgramProvider::BREAKER_SECONDS + 1));
        $freshness->reset($this->now);
        $provider->getPrograms();
        self::assertSame(2, $inner->listCalls);
    }

    /** Final review I1: ProgramsModule's order, both entries expired — one timeout per request, not per entry. */
    public function testAnOutageCostsOneInnerCallPerRequestAcrossEntries(): void
    {
        $this->seed(CachingProgramProvider::sessionKey(self::TOKEN), self::sessionEntry(self::OLD_PROGRAMMES), 400);
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = $inner->identityFailure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        self::assertSame(self::OLD_PROGRAMMES, self::mine($provider));
        self::assertSame(self::OLD_PROGRAMMES, $provider->getPrograms());
        self::assertSame(self::SECTIONS, $provider->getSections());

        self::assertSame(1, $inner->identityCalls + $inner->listCalls);
        self::assertSame([$inner->failure], $this->reported, 'the one failure, reported once');
        self::assertTrue($freshness->isStale());
    }

    /** Final review I1: a missing entry rethrows the request's failure; the expired one is served without a second try. */
    public function testAMissingEntryRethrowsAndTheNextExpiredOneIsServedWithoutAnotherCall(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = $inner->identityFailure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        $thrown = null;
        try {
            self::mine($provider);
        } catch (ConnectException $e) {
            $thrown = $e;
        }
        self::assertSame($inner->identityFailure, $thrown);

        self::assertSame(self::OLD_PROGRAMMES, $provider->getPrograms());
        self::assertSame(self::SECTIONS, $provider->getSections());
        self::assertSame(1, $inner->identityCalls);
        self::assertSame(0, $inner->listCalls);
        self::assertTrue($freshness->isStale());
        self::assertSame([$inner->failure], $this->reported, 'served stale because of it, so Sentry hears of it once');
    }

    /** Final review I1: the failure is remembered for one request only. */
    public function testTheNextRequestTriesAgainAfterAFailure(): void
    {
        $this->seed(CachingProgramProvider::sessionKey(self::TOKEN), self::sessionEntry(self::OLD_PROGRAMMES), 400);
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
        $inner->identityFailure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        self::mine($provider);
        $provider->getPrograms();
        self::assertSame(0, $inner->listCalls);

        // past the breaker: within it the expired entry would be served without asking
        $this->now = $this->now->modify(sprintf('+%d seconds', CachingProgramProvider::BREAKER_SECONDS + 1));
        $freshness->reset($this->now);
        self::assertSame(self::PROGRAMMES, $provider->getPrograms());
        self::assertSame(2, $inner->listCalls, 'kissj is asked again on the next request');
        self::assertFalse($freshness->isStale());
    }

    /** Final review I1: an expired session is an answer, so the list is still asked. */
    public function testAnExpiredSessionDoesNotStopTheListFromBeingAsked(): void
    {
        $this->seed(CachingProgramProvider::sessionKey(self::TOKEN), self::sessionEntry(self::OLD_PROGRAMMES), 400);
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->identityFailure = new SessionExpiredException();
        $provider = $this->provider($inner);

        $thrown = null;
        try {
            self::mine($provider);
        } catch (SessionExpiredException $e) {
            $thrown = $e;
        }
        self::assertSame($inner->identityFailure, $thrown);

        self::assertSame(self::PROGRAMMES, $provider->getPrograms());
        self::assertSame(2, $inner->listCalls);
        self::assertSame([], $this->reported);
    }

    /** Review Focus 2: an older build's file, or a hand edit, is a miss — never a half-array for the module. */
    public function testAnEntryOfTheWrongShapeIsTreatedAsMissing(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['programmes' => self::OLD_PROGRAMMES], 10);
        $this->seed(CachingProgramProvider::sessionKey(self::TOKEN), ['id' => '1', 'nickname' => null, 'programmes' => []], 10);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
        $provider = $this->provider($inner);

        self::assertSame(self::SECTIONS, $provider->getSections());
        self::assertSame(self::PROGRAMMES, self::mine($provider));
        self::assertSame(2, $inner->listCalls);
        self::assertSame(1, $inner->identityCalls);

        // and a wrong-shaped stale entry is no fallback either
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => 'x', 'programmes' => []], 400);
        $inner->failure = self::down();
        $this->expectException(ConnectException::class);
        $this->provider($inner)->getPrograms();
    }

    /** A list entry from before the window was cached, or one whose window is not two instants, is a miss. */
    public function testAListEntryWithoutAUsableWindowIsTreatedAsMissing(): void
    {
        foreach ([
            'no registration' => ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES],
            'no end key' => ['registration' => ['start' => null]] + self::listEntry(self::OLD_PROGRAMMES),
            'not a datetime' => ['registration' => ['start' => 'soon', 'end' => null]] + self::listEntry(self::OLD_PROGRAMMES),
        ] as $label => $entry) {
            $this->tearDown();
            $this->seed(CachingProgramProvider::LIST_KEY, $entry, 10);
            $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);

            self::assertSame(self::PROGRAMMES, $this->provider($inner)->getPrograms(), $label);
            self::assertSame(1, $inner->windowCalls, $label);
        }
    }

    /** Review Focus 3: a cache that cannot be written costs the reader nothing. */
    public function testAnUnwritableCacheStillServesTheFreshAnswer(): void
    {
        $blocker = (string) tempnam(sys_get_temp_dir(), 'cache-blocker');
        try {
            $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
            $provider = $this->provider($inner, cache: new FileCache($blocker . '/cache', $this->clock()));

            self::assertSame(self::PROGRAMMES, $provider->getPrograms());
            self::assertCount(1, $this->reported);
            self::assertInstanceOf(\RuntimeException::class, $this->reported[0]);
        } finally {
            unlink($blocker);
        }
    }

    public function testALoserServesTheExpiredEntryWithoutAskingKissj(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $held = $this->cache()->tryLock(CachingProgramProvider::LIST_KEY);
        self::assertNotNull($held);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $freshness = new Freshness($this->now);

        try {
            self::assertSame(self::OLD_PROGRAMMES, $this->provider($inner, $freshness)->getPrograms());
            self::assertSame(0, $inner->listCalls, 'another request is fetching it right now');
            self::assertFalse($freshness->isStale(), 'being refreshed is not kissj failing');
        } finally {
            $held();
        }
    }

    public function testALoserWithNothingToShowWaitsThenAsksItself(): void
    {
        $held = $this->cache()->tryLock(CachingProgramProvider::LIST_KEY);
        self::assertNotNull($held);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);

        try {
            $start = microtime(true);
            self::assertSame(self::PROGRAMMES, $this->provider($inner, lockWait: 0.2)->getPrograms());
            self::assertGreaterThanOrEqual(0.2, microtime(true) - $start);
            // one inner call is getSections() + getPrograms()
            self::assertSame(2, $inner->listCalls);
        } finally {
            $held();
        }
    }

    public function testAFailureKeepsEveryRequestOffKissjForSixtySecondsWhileAnEntryExists(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = self::down();
        $freshness = new Freshness($this->now);

        self::assertSame(self::OLD_PROGRAMMES, $this->provider($inner, $freshness)->getPrograms());
        self::assertSame(1, $inner->listCalls);
        self::assertTrue($freshness->isStale());

        $start = $this->now;
        $this->now = $start->modify('+30 seconds');
        $freshness->reset($this->now);
        $healthy = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        self::assertSame(self::OLD_PROGRAMMES, $this->provider($healthy, $freshness)->getPrograms());
        self::assertSame(0, $healthy->listCalls, 'the breaker is open');
        self::assertTrue($freshness->isStale());

        $this->now = $start->modify('+61 seconds');
        $freshness->reset($this->now);
        $healthy = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        self::assertSame(self::PROGRAMMES, $this->provider($healthy, $freshness)->getPrograms());
        self::assertSame(2, $healthy->listCalls, 'one inner fetch: getSections() + getPrograms()');
        self::assertSame(self::PROGRAMMES, $this->cache()->get(CachingProgramProvider::LIST_KEY)->data['programmes']);
        self::assertFalse($freshness->isStale());
    }

    public function testTheBreakerNeverHoldsBackAMissingEntry(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = self::down();
        $this->provider($inner)->getPrograms();
        self::assertNotNull($this->cache()->get(CachingProgramProvider::DOWN_KEY), 'the breaker is open');

        $freshness = new Freshness($this->now);
        $healthy = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
        self::assertSame(self::PROGRAMMES, self::mine($this->provider($healthy, $freshness)));
        self::assertSame(1, $healthy->identityCalls);
    }

    public function testASuccessClosesTheBreaker(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = self::down();
        $this->provider($inner)->getPrograms();
        self::assertNotNull($this->cache()->get(CachingProgramProvider::DOWN_KEY));

        $this->now = $this->now->modify('+61 seconds');
        $this->provider(new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES))->getPrograms();

        self::assertNull($this->cache()->get(CachingProgramProvider::DOWN_KEY));
        self::assertNotNull($this->cache()->get(CachingProgramProvider::REPORTED_KEY), 'a success forgets the outage, not the report');
    }

    public function testAHiddenFailureIsReportedAtMostOncePerFiveMinutes(): void
    {
        $start = $this->now;
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        foreach ([0, 61, 122] as $offset) {
            $this->now = $start->modify(sprintf('+%d seconds', $offset));
            $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
            $inner->failure = self::down();
            self::assertSame(self::OLD_PROGRAMMES, $this->provider($inner)->getPrograms());
            self::assertSame(1, $inner->listCalls, sprintf('asked at +%d s', $offset));
        }
        self::assertCount(1, $this->reported);

        $this->now = $start->modify('+305 seconds');
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = self::down();
        $this->provider($inner)->getPrograms();
        self::assertCount(2, $this->reported);
    }

    /** A flapping kissj: a success in between does not reset the five minutes. */
    public function testASuccessInBetweenDoesNotReopenReporting(): void
    {
        $start = $this->now;
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $failing = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $failing->failure = self::down();
        $this->provider($failing)->getPrograms();

        $this->now = $start->modify('+61 seconds');
        $this->provider(new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES))->getPrograms();

        // within five minutes of the first report: the entry is old again and kissj fails again
        $this->now = $start->modify('+200 seconds');
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        $failing = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $failing->failure = self::down();
        $this->provider($failing)->getPrograms();
        self::assertSame(1, $failing->listCalls);

        self::assertCount(1, $this->reported);
    }

    /** Review T6-M1: during an outage a `locked` read is an old copy like any other. */
    public function testALockedReadIsStaleWhileKissjIsMarkedDown(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, self::listEntry(self::OLD_PROGRAMMES), 400);
        // a failure long enough ago that the breaker has closed, and nothing has succeeded since
        $this->seed(CachingProgramProvider::DOWN_KEY, null, 3600);
        $held = $this->cache()->tryLock(CachingProgramProvider::LIST_KEY);
        self::assertNotNull($held);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $freshness = new Freshness($this->now);

        try {
            self::assertSame(self::OLD_PROGRAMMES, $this->provider($inner, $freshness)->getPrograms());
            self::assertSame(0, $inner->listCalls);
            self::assertTrue($freshness->isStale(), 'the probe holding the lock may well fail too');
        } finally {
            $held();
        }
    }

    /** A FileCache whose lock is held on the first try and, while this request waits, $during runs. */
    private function contendedCache(\Closure $during): FileCache
    {
        $tries = 0;

        return new FileCache($this->dir, $this->clock(), static function ($handle, int $operation, &$wouldBlock = null) use (&$tries, $during): bool {
            if ($tries++ === 0) {
                $wouldBlock = 1;

                return false;
            }
            $during();

            return flock($handle, $operation, $wouldBlock);
        });
    }

    /** Review T6-M2: the holder failed while this request waited, so this one does not pay a timeout too. */
    public function testAWaiterWhoseHolderFailedDoesNotAskKissjAgain(): void
    {
        $cache = $this->contendedCache(fn () => $this->cache()->set(CachingProgramProvider::DOWN_KEY, null));
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $provider = $this->provider($inner, cache: $cache);

        try {
            $provider->getPrograms();
            self::fail('there is nothing to show and the holder failed');
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            self::assertStringNotContainsString('KORBO', $e->getMessage());
        }
        self::assertSame(0, $inner->listCalls);
        self::assertSame([], $this->reported, 'a rethrown failure is the module\'s notice');
    }

    public function testAWaiterAsksItselfWhenTheFailureWasBeforeItsWait(): void
    {
        $this->seed(CachingProgramProvider::DOWN_KEY, null, 30);
        $cache = $this->contendedCache(static function (): void {
        });
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);

        self::assertSame(self::PROGRAMMES, $this->provider($inner, cache: $cache)->getPrograms());
        self::assertSame(2, $inner->listCalls, 'one inner fetch: getSections() + getPrograms()');
    }
}

/** The inner provider, counting what reaches it. */
final class FakeProgramProvider implements ProgramProviderInterface
{
    /** getSections() and getPrograms() calls together */
    public int $listCalls = 0;

    public int $windowCalls = 0;

    public int $identityCalls = 0;

    public int $participantIdCalls = 0;

    public int $startCalls = 0;

    public int $pollCalls = 0;

    public int $revokeCalls = 0;

    /** @var array{register: list<array{string, int}>, unregister: list<array{string, int}>} */
    public array $writes = ['register' => [], 'unregister' => []];

    /** thrown by getSections(), getPrograms() and getRegistrationWindow() */
    public ?\Throwable $failure = null;

    /** thrown by getParticipant() */
    public ?\Throwable $identityFailure = null;

    /** thrown by register() and unregister() */
    public ?\Throwable $writeFailure = null;

    /** thrown by revokeSession() */
    public ?\Throwable $revokeFailure = null;

    /** run by getParticipant() after it has its answer: what another request does meanwhile */
    public ?\Closure $duringIdentity = null;

    public function __construct(
        private readonly array $sections = [],
        private readonly array $programmes = [],
        private readonly array $mine = [],
    ) {
    }

    public function getPrograms(): array
    {
        $this->listCalls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->programmes;
    }

    public function getSections(): array
    {
        $this->listCalls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->sections;
    }

    public function getRegistrationWindow(): RegistrationWindow
    {
        $this->windowCalls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new RegistrationWindow(
            new \DateTimeImmutable('2027-03-15T18:00:00+01:00'),
            new \DateTimeImmutable('2027-05-19T00:00:00+02:00'),
        );
    }

    public function getParticipant(string $sessionToken): Participant
    {
        $this->identityCalls++;
        if ($this->identityFailure !== null) {
            throw $this->identityFailure;
        }
        $answer = new Participant(1, 'Jana', $this->mine);
        if ($this->duringIdentity !== null) {
            ($this->duringIdentity)();
        }

        return $answer;
    }

    public function getParticipantIdsForProgramme(int $programmeId): array
    {
        $this->participantIdCalls++;

        return [1];
    }

    public function startPairing(string $deviceId): Pairing
    {
        $this->startCalls++;

        return new Pairing('0123456789abcdef0123456789abcdef', '483291', 'https://kissj.example/pairing', new \DateTimeImmutable('+1 hour'), 5);
    }

    public function pollPairing(string $deviceCode): PairingPoll
    {
        $this->pollCalls++;

        return new PairingPoll(PairingStatus::Approved, 'stub-1-' . bin2hex(random_bytes(8)), new \DateTimeImmutable('+6 months'));
    }

    public function register(string $sessionToken, int $programmeId): void
    {
        $this->writes['register'][] = [$sessionToken, $programmeId];
        if ($this->writeFailure !== null) {
            throw $this->writeFailure;
        }
    }

    public function unregister(string $sessionToken, int $programmeId): void
    {
        $this->writes['unregister'][] = [$sessionToken, $programmeId];
        if ($this->writeFailure !== null) {
            throw $this->writeFailure;
        }
    }

    public function revokeSession(string $sessionToken): void
    {
        $this->revokeCalls++;
        if ($this->revokeFailure !== null) {
            throw $this->revokeFailure;
        }
    }
}
