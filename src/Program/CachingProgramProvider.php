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
 * Installed by Kernel around KissjProgramProvider only: the stub's fixture files are
 * already a local copy.
 */
final class CachingProgramProvider implements ProgramProviderInterface
{
    /** sections and programmes together, as one list response brings them */
    public const LIST_KEY = 'list';

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
     */
    public function __construct(
        private readonly ProgramProviderInterface $inner,
        private readonly FileCache $cache,
        private readonly Freshness $freshness,
        private readonly int $ttl,
        ?\Closure $now = null,
        ?\Closure $report = null,
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
        $this->freshness->note($outcome['fetchedAt'], $outcome['result'] === 'stale');
        $this->memo[$key] = ['generation' => $this->freshness->generation(), 'data' => $outcome['data']];

        return $outcome['data'];
    }

    /**
     * @return array{data: array, result: 'hit'|'miss'|'stale', age: ?int, fetchedAt: \DateTimeImmutable}
     */
    private function resolve(string $key, \Closure $fetch, \Closure $usable): array
    {
        $now = ($this->now)();
        $entry = $this->cache->get($key);
        if ($entry !== null && !$usable($entry->data)) {
            $entry = null;
        }

        if ($entry !== null && $entry->isFresh($this->ttl, $now)) {
            return self::served($entry, 'hit', $now);
        }

        $generation = $this->freshness->generation();
        if ($this->failure !== null && $this->failure['generation'] === $generation) {
            return $this->fallBack($entry, $now);
        }

        try {
            $data = $fetch();
        } catch (TransferException|ProgramDataException $e) {
            // UnknownParticipantException is an answer and passes by without tripping this
            $this->failure = ['generation' => $generation, 'error' => $e, 'reported' => false];

            return $this->fallBack($entry, $now);
        }

        try {
            $this->cache->set($key, $data);
        } catch (\RuntimeException $e) {
            // the reader has the fresh answer; only the next request pays for the lost write
            ($this->report)($e);
        }

        return ['data' => $data, 'result' => 'miss', 'age' => null, 'fetchedAt' => $now];
    }

    /**
     * After this request's failure: the expired entry, however old, or the failure itself.
     * It reaches Sentry once, and only when an entry hid it — a rethrown one is the module's
     * notice.
     *
     * @return array{data: array, result: string, age: int, fetchedAt: \DateTimeImmutable}
     */
    private function fallBack(?CacheEntry $entry, \DateTimeImmutable $now): array
    {
        \assert($this->failure !== null);
        if ($entry === null) {
            throw $this->failure['error'];
        }
        if (!$this->failure['reported']) {
            ($this->report)($this->failure['error']);
            $this->failure['reported'] = true;
        }

        return self::served($entry, 'stale', $now);
    }

    /** @return array{data: array, result: string, age: int, fetchedAt: \DateTimeImmutable} */
    private static function served(CacheEntry $entry, string $result, \DateTimeImmutable $now): array
    {
        return ['data' => $entry->data, 'result' => $result, 'age' => $entry->ageAt($now), 'fetchedAt' => $entry->fetchedAt];
    }
}
