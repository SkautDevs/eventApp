<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;
use App\Cache\FileCache;
use App\Program\CachingProgramProvider;
use App\Program\Freshness;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class CachingProgramProviderTest extends TestCase
{
    private const array SECTIONS = [
        10 => ['id' => 10, 'title' => 'Putování', 'subTitle' => null, 'image' => null, 'attachment' => null],
        1 => ['id' => 1, 'title' => 'Hlavní program', 'subTitle' => null, 'image' => null, 'attachment' => null],
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

    private static function identity(string $code = 'KORBO1'): Identity
    {
        return new Identity(displayName: 'TIE ' . $code, tieCode: $code);
    }

    private static function down(): ConnectException
    {
        return new ConnectException('down', new Request('GET', 'v3/programme/list'));
    }

    public function testAFreshEntryMakesNoInnerCall(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 299);
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

        // one fetch is getSections() + getPrograms() on the inner provider
        self::assertSame(2, $inner->listCalls);
        $entry = $this->cache()->get(CachingProgramProvider::LIST_KEY);
        self::assertSame(['sections' => self::SECTIONS, 'programmes' => self::PROGRAMMES], $entry->data);
        self::assertSame($this->now->format(\DATE_ATOM), $entry->fetchedAt->format(\DATE_ATOM));
    }

    public function testAnExpiredEntryIsRefetchedAndRewritten(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 301);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);

        self::assertSame(self::PROGRAMMES, $this->provider($inner)->getPrograms());

        self::assertSame(2, $inner->listCalls);
        self::assertSame(self::PROGRAMMES, $this->cache()->get(CachingProgramProvider::LIST_KEY)->data['programmes']);
        self::assertSame([], $this->reported);
    }

    public function testAnExpiredEntryIsServedWhenKissjCannotBeReached(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 86400 * 2);
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
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 301);
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

    public function testTieCodesForAProgrammeAreNeverCached(): void
    {
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $provider = $this->provider($inner);

        self::assertSame(['KORBO1'], $provider->getTieCodesForProgramme(5));
        self::assertSame(['KORBO1'], $provider->getTieCodesForProgramme(5));

        self::assertSame(2, $inner->tieCodeCalls);
        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testAnUnknownParticipantDeletesTheEntryAndRethrows(): void
    {
        $key = CachingProgramProvider::tieKey('KORBO1');
        $this->seed($key, self::OLD_PROGRAMMES, 301);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->identityFailure = new UnknownParticipantException('Unknown TIE code');

        try {
            $this->provider($inner)->getProgramsForIdentity(self::identity());
            self::fail('a participant kissj has deleted must not stay logged in from the cache');
        } catch (UnknownParticipantException $e) {
            self::assertSame($inner->identityFailure, $e);
        }
        self::assertNull($this->cache()->get($key));
        self::assertSame([], $this->reported, 'an answer, not an outage');
    }

    public function testTheTieEntryIsNamedByAHashAndHoldsOnlyTheProgrammes(): void
    {
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);

        self::assertSame(self::PROGRAMMES, $this->provider($inner)->getProgramsForIdentity(self::identity('KORBO1')));

        $expected = 'tie-' . substr(hash('sha256', 'KORBO1'), 0, 32);
        self::assertSame($expected, CachingProgramProvider::tieKey('KORBO1'));
        // the entry and the lock that serialises fetching it: both named by the hash
        self::assertSame([$expected . '.json', $expected . '.lock'], array_map('basename', glob($this->dir . '/*') ?: []));
        self::assertStringNotContainsString('KORBO1', (string) file_get_contents($this->dir . '/' . $expected . '.json'));
        self::assertSame(self::PROGRAMMES, $this->cache()->get($expected)->data);
    }

    public function testFreshnessCarriesTheOldestFetchAndStaleAfterAMixedRequest(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::PROGRAMMES], 100);
        $this->seed(CachingProgramProvider::tieKey('KORBO1'), self::OLD_PROGRAMMES, 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->identityFailure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        $provider->getPrograms();
        self::assertSame(['fetchedAt' => '2026-10-04T11:58:20+02:00', 'stale' => false], $freshness->forView(), 'a fresh hit alone');

        $provider->getProgramsForIdentity(self::identity());
        self::assertSame(['fetchedAt' => '2026-10-04T11:53:20+02:00', 'stale' => true], $freshness->forView(), 'the oldest, and stale');
    }

    public function testTtlZeroRefetchesYetServesStaleOnError(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 0);
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
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 301);
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
        $this->seed(CachingProgramProvider::tieKey('KORBO1'), self::OLD_PROGRAMMES, 400);
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = $inner->identityFailure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        self::assertSame(self::OLD_PROGRAMMES, $provider->getProgramsForIdentity(self::identity()));
        self::assertSame(self::OLD_PROGRAMMES, $provider->getPrograms());
        self::assertSame(self::SECTIONS, $provider->getSections());

        self::assertSame(1, $inner->identityCalls + $inner->listCalls);
        self::assertSame([$inner->failure], $this->reported, 'the one failure, reported once');
        self::assertTrue($freshness->isStale());
    }

    /** Final review I1: a missing entry rethrows the request's failure; the expired one is served without a second try. */
    public function testAMissingEntryRethrowsAndTheNextExpiredOneIsServedWithoutAnotherCall(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = $inner->identityFailure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        $thrown = null;
        try {
            $provider->getProgramsForIdentity(self::identity());
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
        $this->seed(CachingProgramProvider::tieKey('KORBO1'), self::OLD_PROGRAMMES, 400);
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
        $inner->identityFailure = self::down();
        $freshness = new Freshness($this->now);
        $provider = $this->provider($inner, $freshness);

        $provider->getProgramsForIdentity(self::identity());
        $provider->getPrograms();
        self::assertSame(0, $inner->listCalls);

        // past the breaker: within it the expired entry would be served without asking
        $this->now = $this->now->modify(sprintf('+%d seconds', CachingProgramProvider::BREAKER_SECONDS + 1));
        $freshness->reset($this->now);
        self::assertSame(self::PROGRAMMES, $provider->getPrograms());
        self::assertSame(2, $inner->listCalls, 'kissj is asked again on the next request');
        self::assertFalse($freshness->isStale());
    }

    /** Final review I1: an unknown participant is an answer, so the list is still asked. */
    public function testAnUnknownParticipantDoesNotStopTheListFromBeingAsked(): void
    {
        $this->seed(CachingProgramProvider::tieKey('KORBO1'), self::OLD_PROGRAMMES, 400);
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->identityFailure = new UnknownParticipantException('Unknown TIE code');
        $provider = $this->provider($inner);

        $thrown = null;
        try {
            $provider->getProgramsForIdentity(self::identity());
        } catch (UnknownParticipantException $e) {
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
        $this->seed(CachingProgramProvider::tieKey('KORBO1'), ['not' => 'a list'], 10);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
        $provider = $this->provider($inner);

        self::assertSame(self::SECTIONS, $provider->getSections());
        self::assertSame(self::PROGRAMMES, $provider->getProgramsForIdentity(self::identity()));
        self::assertSame(2, $inner->listCalls);
        self::assertSame(1, $inner->identityCalls);

        // and a wrong-shaped stale entry is no fallback either
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => 'x', 'programmes' => []], 400);
        $inner->failure = self::down();
        $this->expectException(ConnectException::class);
        $this->provider($inner)->getPrograms();
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
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
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
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
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
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
        $inner = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $inner->failure = self::down();
        $this->provider($inner)->getPrograms();
        self::assertNotNull($this->cache()->get(CachingProgramProvider::DOWN_KEY), 'the breaker is open');

        $freshness = new Freshness($this->now);
        $healthy = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES, mine: self::PROGRAMMES);
        self::assertSame(self::PROGRAMMES, $this->provider($healthy, $freshness)->getProgramsForIdentity(self::identity()));
        self::assertSame(1, $healthy->identityCalls);
    }

    public function testASuccessClosesTheBreaker(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
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
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
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
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
        $failing = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $failing->failure = self::down();
        $this->provider($failing)->getPrograms();

        $this->now = $start->modify('+61 seconds');
        $this->provider(new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES))->getPrograms();

        // within five minutes of the first report: the entry is old again and kissj fails again
        $this->now = $start->modify('+200 seconds');
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
        $failing = new FakeProgramProvider(self::SECTIONS, self::PROGRAMMES);
        $failing->failure = self::down();
        $this->provider($failing)->getPrograms();
        self::assertSame(1, $failing->listCalls);

        self::assertCount(1, $this->reported);
    }

    /** Review T6-M1: during an outage a `locked` read is an old copy like any other. */
    public function testALockedReadIsStaleWhileKissjIsMarkedDown(): void
    {
        $this->seed(CachingProgramProvider::LIST_KEY, ['sections' => self::SECTIONS, 'programmes' => self::OLD_PROGRAMMES], 400);
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

    public int $identityCalls = 0;

    public int $tieCodeCalls = 0;

    public ?\Throwable $failure = null;

    public ?\Throwable $identityFailure = null;

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

    public function getProgramsForIdentity(Identity $identity): array
    {
        $this->identityCalls++;
        if ($this->identityFailure !== null) {
            throw $this->identityFailure;
        }

        return $this->mine;
    }

    public function getTieCodesForProgramme(int $programmeId): array
    {
        $this->tieCodeCalls++;

        return ['KORBO1'];
    }
}
