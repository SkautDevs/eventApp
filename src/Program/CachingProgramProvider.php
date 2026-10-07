<?php

declare(strict_types=1);

namespace App\Program;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;
use App\Cache\CacheEntry;
use App\Cache\FileCache;
use App\Telemetry\Collector;
use App\Telemetry\Tracer;
use GuzzleHttp\Exception\TransferException;

/**
 * kissj's answers, kept on disk. A fresh entry is served without asking; an expired or
 * missing one is fetched and rewritten; an expired one is served — however old — when
 * kissj fails, and the failure goes to Sentry instead of the reader. Only a missing entry
 * lets kissj's trouble through to the modules' notices.
 *
 * Under load, three more rules keep kissj from being asked by every request at once:
 * - single-flight per entry: one request holds `<key>.lock` and fetches; another that has
 *   an expired entry serves it as it is (`cache.result` `locked` — being refreshed, not
 *   stale, so no "naposledy načteno" — unless `kissj-down` exists, when the probe holding
 *   the lock may well fail too and the copy is marked stale), and one with nothing to show
 *   waits up to $lockWait seconds for the holder's answer before asking itself — unless the
 *   holder failed meanwhile, when it rethrows without a timeout of its own;
 * - a breaker across requests: a failure writes the `kissj-down` entry, and for
 *   BREAKER_SECONDS after it an expired entry is served stale without asking. A missing
 *   entry still asks — the breaker never turns a slow page into an empty one. A success
 *   deletes the marker;
 * - a hidden failure reaches Sentry at most once per REPORT_SECONDS across all requests,
 *   remembered in `kissj-reported`, which a success leaves alone — a flapping kissj would
 *   otherwise be reported on every flap.
 * Where the cache directory cannot be written all three quietly do nothing, and the
 * provider behaves as it did without them.
 *
 * Installed by Kernel around KissjProgramProvider only: the stub's fixture files are
 * already a local copy.
 */
final class CachingProgramProvider implements ProgramProviderInterface
{
    /** sections and programmes together, as one list response brings them */
    public const string LIST_KEY = 'list';

    /** how long after a failure an expired entry is served without asking kissj */
    public const int BREAKER_SECONDS = 60;

    /** at most one report of a hidden failure per this many seconds */
    public const int REPORT_SECONDS = 300;

    /** the breaker's marker: its fetchedAt is the last failure */
    public const string DOWN_KEY = 'kissj-down';

    /** when a hidden failure was last reported: its fetchedAt; a success keeps it */
    public const string REPORTED_KEY = 'kissj-reported';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /** @var \Closure(\Throwable): void */
    private readonly \Closure $report;

    /** @var array<string, array{generation: int, data: array}> what this request already resolved */
    private array $memo = [];

    /**
     * @var array{generation: int, error: \Throwable, reported: bool}|null the inner provider's
     *      failure in this request: every later expired entry is served stale without asking
     *      again, so an outage costs one timeout per request however many entries a page reads
     */
    private ?array $failure = null;

    /**
     * @param int $ttl seconds an entry is served without asking; 0 asks every time
     * @param (\Closure(): \DateTimeImmutable)|null $now the clock; tests pass a fixed one
     * @param (\Closure(\Throwable): void)|null $report where a hidden failure goes; Sentry by default
     * @param float $lockWait seconds a request with no entry waits for another one fetching it
     */
    public function __construct(
        private readonly ProgramProviderInterface $inner,
        private readonly FileCache $cache,
        private readonly Freshness $freshness,
        private readonly int $ttl,
        ?\Closure $now = null,
        ?\Closure $report = null,
        private readonly float $lockWait = 12.0,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->report = $report ?? Collector::collect(...);
    }

    public function inner(): ProgramProviderInterface
    {
        return $this->inner;
    }

    /** The code is the participant's access secret, so no filename carries it. */
    public static function tieKey(string $tieCode): string
    {
        return 'tie-' . substr(hash('sha256', $tieCode), 0, 32);
    }

    public function getPrograms(): array
    {
        return $this->list()['programmes'];
    }

    public function getSections(): array
    {
        return $this->list()['sections'];
    }

    public function getProgramsForIdentity(Identity $identity): array
    {
        $key = self::tieKey($identity->tieCode);
        try {
            return $this->read(
                $key,
                fn (): array => $this->inner->getProgramsForIdentity($identity),
                static fn (mixed $data): bool => is_array($data) && array_is_list($data),
            );
        } catch (UnknownParticipantException $e) {
            // An answer, not an outage: a stale entry here would keep a participant kissj
            // has deleted logged in and marked.
            $this->cache->delete($key);
            unset($this->memo[$key]);
            throw $e;
        }
    }

    /**
     * Never cached: an organiser sending to one programme wants who is registered now, and
     * a few calls a day from one browser cost nothing.
     */
    public function getTieCodesForProgramme(int $programmeId): array
    {
        return Tracer::span(
            'program.cache',
            'programme-participants',
            fn (): array => $this->inner->getTieCodesForProgramme($programmeId),
            ['cache.result' => 'bypass'],
        );
    }

    /** @return array{sections: array, programmes: list<array>} */
    private function list(): array
    {
        return $this->read(
            self::LIST_KEY,
            // the inner provider memoises its list response: one HTTP request for both
            fn (): array => ['sections' => $this->inner->getSections(), 'programmes' => $this->inner->getPrograms()],
            static fn (mixed $data): bool => is_array($data) && is_array($data['sections'] ?? null) && is_array($data['programmes'] ?? null),
        );
    }

    /**
     * @param \Closure(): array $fetch the inner call
     * @param \Closure(mixed): bool $usable whether a stored entry has the shape this key promises
     */
    private function read(string $key, \Closure $fetch, \Closure $usable): array
    {
        $memo = $this->memo[$key] ?? null;
        if ($memo !== null && $memo['generation'] === $this->freshness->generation()) {
            return $memo['data'];
        }

        $outcome = Tracer::span('program.cache', $key, fn (): array => $this->resolve($key, $fetch, $usable), [
            Tracer::FROM_RESULT => static fn (array $outcome): array => array_filter(
                ['cache.result' => $outcome['result'], 'cache.age' => $outcome['age']],
                static fn (mixed $value): bool => $value !== null,
            ),
        ]);
        $this->freshness->note($outcome['fetchedAt'], $outcome['stale']);
        $this->memo[$key] = ['generation' => $this->freshness->generation(), 'data' => $outcome['data']];

        return $outcome['data'];
    }

    /**
     * @return array{data: array, result: 'hit'|'miss'|'stale'|'locked', age: ?int, fetchedAt: \DateTimeImmutable, stale: bool}
     */
    private function resolve(string $key, \Closure $fetch, \Closure $usable): array
    {
        $now = ($this->now)();
        $entry = $this->usableEntry($key, $usable);
        if ($entry !== null && $entry->isFresh($this->ttl, $now)) {
            return self::served($entry, 'hit', $now);
        }

        $generation = $this->freshness->generation();
        if ($this->failure !== null && $this->failure['generation'] === $generation) {
            return $this->fallBack($entry, $now);
        }

        // kissj failed moments ago, in this request or another: an entry that exists is
        // served as it is rather than costing this request a timeout too. A missing one asks.
        if ($entry !== null && $this->breakerOpen($now)) {
            return self::served($entry, 'stale', $now);
        }

        $release = $this->cache->tryLock($key);
        $waitedSince = null;
        if ($release === null) {
            // Another request is fetching this very entry right now. While kissj is marked
            // down that probe may well fail too, so the copy is as old as any stale one.
            if ($entry !== null) {
                return self::served($entry, 'locked', $now, stale: $this->cache->get(self::DOWN_KEY) !== null);
            }
            $waitedSince = ($this->now)();
            $release = $this->cache->waitLock($key, $this->lockWait);
        }

        try {
            $current = $this->usableEntry($key, $usable);
            // the request we waited for, or one that finished between our read and our lock
            // (only a request with no entry waits, so after a wait any entry is the holder's)
            if ($current !== null && ($waitedSince !== null || $current->isFresh($this->ttl, $now))) {
                return self::served($current, 'hit', $now);
            }
            // the request we waited for failed: one timeout per cold entry, not one per waiter
            if ($waitedSince !== null && $this->failedSince($waitedSince)) {
                $this->failure = [
                    'generation' => $generation,
                    'error' => new TransferException('kissj failed for the request this one waited for'),
                    'reported' => false,
                ];

                return $this->fallBack($current, $now);
            }

            try {
                $data = $fetch();
            } catch (TransferException|ProgramDataException $e) {
                // UnknownParticipantException is an answer and passes by without tripping this
                $this->failure = ['generation' => $generation, 'error' => $e, 'reported' => false];
                $this->markDown();

                return $this->fallBack($current ?? $entry, $now);
            }

            $this->cache->delete(self::DOWN_KEY);
            try {
                $this->cache->set($key, $data);
            } catch (\RuntimeException $e) {
                // the reader has the fresh answer; only the next request pays for the lost write
                ($this->report)($e);
            }

            return ['data' => $data, 'result' => 'miss', 'age' => null, 'fetchedAt' => $now, 'stale' => false];
        } finally {
            $release();
        }
    }

    private function usableEntry(string $key, \Closure $usable): ?CacheEntry
    {
        $entry = $this->cache->get($key);

        return $entry !== null && $usable($entry->data) ? $entry : null;
    }

    private function breakerOpen(\DateTimeImmutable $now): bool
    {
        return self::within($this->cache->get(self::DOWN_KEY), $now, self::BREAKER_SECONDS);
    }

    /** Whether a failure was marked at or after $since (whole seconds, as the marker keeps them). */
    private function failedSince(\DateTimeImmutable $since): bool
    {
        $down = $this->cache->get(self::DOWN_KEY);

        return $down !== null && $down->fetchedAt->getTimestamp() >= $since->getTimestamp();
    }

    private function markDown(): void
    {
        try {
            $this->cache->set(self::DOWN_KEY, null);
        } catch (\RuntimeException) {
            // an unwritable cache directory: the breaker simply stays shut
        }
    }

    /** At most one report of a hidden kissj failure per REPORT_SECONDS across all requests. */
    private function mayReport(\DateTimeImmutable $now): bool
    {
        if (self::within($this->cache->get(self::REPORTED_KEY), $now, self::REPORT_SECONDS)) {
            return false;
        }
        try {
            $this->cache->set(self::REPORTED_KEY, null);
        } catch (\RuntimeException) {
            // unwritable: every request reports, as before the throttle
        }

        return true;
    }

    /** Whether a marker entry was written less than $seconds before $now (a future one counts as not). */
    private static function within(?CacheEntry $marker, \DateTimeImmutable $now, int $seconds): bool
    {
        $age = $marker?->ageAt($now);

        return $age !== null && $age >= 0 && $age < $seconds;
    }

    /**
     * After this request's failure: the expired entry, however old, or the failure itself.
     * It reaches Sentry at most once per request and once per REPORT_SECONDS overall, and only when an entry hid it — a rethrown one is the module's
     * notice.
     *
     * @return array{data: array, result: string, age: int, fetchedAt: \DateTimeImmutable, stale: bool}
     */
    private function fallBack(?CacheEntry $entry, \DateTimeImmutable $now): array
    {
        \assert($this->failure !== null);
        if ($entry === null) {
            throw $this->failure['error'];
        }
        if (!$this->failure['reported']) {
            $this->failure['reported'] = true;
            if ($this->mayReport($now)) {
                ($this->report)($this->failure['error']);
            }
        }

        return self::served($entry, 'stale', $now);
    }

    /**
     * @param bool|null $stale whether the reader is told it is an old copy; by default exactly when the result is `stale`
     *
     * @return array{data: array, result: string, age: int, fetchedAt: \DateTimeImmutable, stale: bool}
     */
    private static function served(CacheEntry $entry, string $result, \DateTimeImmutable $now, ?bool $stale = null): array
    {
        return [
            'data' => $entry->data,
            'result' => $result,
            'age' => $entry->ageAt($now),
            'fetchedAt' => $entry->fetchedAt,
            'stale' => $stale ?? $result === 'stale',
        ];
    }
}
