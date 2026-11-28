<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Inbox\InboxMessage;
use App\Inbox\InboxRepository;
use App\Inbox\Kind;
use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\TestCase;
use Tests\FixedClock;
use Tests\Functional\AppTestCase;

final class InboxRepositoryTest extends TestCase
{
    private FixedClock $clock;

    private InboxRepository $repo;

    protected function setUp(): void
    {
        $this->clock = new FixedClock($this->at('2026-07-15 10:00:00'));
        $this->repo = new InboxRepository(AppTestCase::memoryDb(), $this->clock);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Prague'));
    }

    /** @param list<InboxMessage> $messages @return list<int> */
    private static function ids(array $messages): array
    {
        return array_map(static fn(InboxMessage $message): int => $message->id, $messages);
    }

    private function only(string $event): InboxMessage
    {
        $all = [...$this->repo->unhandled($event), ...$this->repo->handledPage($event, 1, 100)];
        self::assertCount(1, $all);

        return $all[0];
    }

    public function testAddReturnsTheIdAndTheRowReadsBack(): void
    {
        $id = $this->repo->add('korbo26', 42, 'Kája', Kind::Help, "Řádek 1\nŘádek 2", '777 123 456');

        self::assertSame([
            'id' => $id, 'event' => 'korbo26', 'sentAt' => '2026-07-15 10:00:00', 'participantId' => 42,
            'nickname' => 'Kája', 'kind' => Kind::Help, 'body' => "Řádek 1\nŘádek 2", 'contact' => '777 123 456',
            'handled' => false, 'handledAt' => null, 'handledBy' => null,
        ], get_object_vars($this->only('korbo26')));
    }

    public function testSenderNamesTheNicknameOrTheGenericParticipant(): void
    {
        $this->repo->add('korbo26', 42, 'Kája', Kind::Idea, 'text', null);
        $this->repo->add('obrok27', 42, null, Kind::Idea, 'text', null);

        self::assertSame('Kája · č. 42', $this->only('korbo26')->sender());
        self::assertSame('Účastník · č. 42', $this->only('obrok27')->sender());
        self::assertNull($this->only('obrok27')->nickname);
        self::assertNull($this->only('obrok27')->contact);
    }

    public function testUnhandledIsNewestFirstAndOfThisEventOnly(): void
    {
        $old = $this->repo->add('korbo26', 1, null, Kind::Help, 'a', null, $this->at('2026-07-15 08:00:00'));
        $new = $this->repo->add('korbo26', 2, null, Kind::Idea, 'b', null, $this->at('2026-07-15 09:00:00'));
        $this->repo->add('obrok27', 3, null, Kind::Help, 'c', null, $this->at('2026-07-15 09:30:00'));
        $handled = $this->repo->add('korbo26', 4, null, Kind::Help, 'd', null, $this->at('2026-07-15 09:45:00'));
        $this->repo->setHandled('korbo26', $handled, true, 'Lung');

        self::assertSame([$new, $old], self::ids($this->repo->unhandled('korbo26')));
        self::assertSame(2, $this->repo->countUnhandled('korbo26'));
        self::assertSame(1, $this->repo->countUnhandled('obrok27'));
    }

    public function testCountSinceCountsOnlyThisParticipantAndEventAfterTheInstant(): void
    {
        $since = $this->at('2026-07-15 09:00:00');
        $this->repo->add('korbo26', 42, null, Kind::Help, 'before', null, $this->at('2026-07-15 08:59:59'));
        $this->repo->add('korbo26', 42, null, Kind::Help, 'at the instant', null, $since);
        $this->repo->add('korbo26', 42, null, Kind::Help, 'after', null, $this->at('2026-07-15 09:00:01'));
        $this->repo->add('korbo26', 42, null, Kind::Idea, 'after, other kind', null, $this->at('2026-07-15 09:30:00'));
        $this->repo->add('korbo26', 43, null, Kind::Help, 'another participant', null, $this->at('2026-07-15 09:30:00'));
        $this->repo->add('obrok27', 42, null, Kind::Help, 'another event', null, $this->at('2026-07-15 09:30:00'));

        self::assertSame(2, $this->repo->countSince('korbo26', 42, $since));
        self::assertSame(0, $this->repo->countSince('korbo26', 44, $since));
    }

    public function testExistsSinceNeedsTheSameKindAndText(): void
    {
        $since = $this->at('2026-07-15 09:59:00');
        $this->repo->add('korbo26', 42, null, Kind::Help, 'Ztratil jsem klíče', null, $this->at('2026-07-15 09:59:30'));
        $this->repo->add('korbo26', 42, null, Kind::Help, 'Starší', null, $this->at('2026-07-15 09:58:00'));

        self::assertTrue($this->repo->existsSince('korbo26', 42, Kind::Help, 'Ztratil jsem klíče', $since));
        self::assertFalse($this->repo->existsSince('korbo26', 42, Kind::Idea, 'Ztratil jsem klíče', $since));
        self::assertFalse($this->repo->existsSince('korbo26', 42, Kind::Help, 'Ztratil jsem klíče!', $since));
        self::assertFalse($this->repo->existsSince('korbo26', 43, Kind::Help, 'Ztratil jsem klíče', $since));
        self::assertFalse($this->repo->existsSince('obrok27', 42, Kind::Help, 'Ztratil jsem klíče', $since));
        self::assertFalse($this->repo->existsSince('korbo26', 42, Kind::Help, 'Starší', $since));
    }

    public function testSetHandledRecordsWhoAndWhenAndIsUndone(): void
    {
        $id = $this->repo->add('korbo26', 42, null, Kind::Help, 'text', null, $this->at('2026-07-15 09:00:00'));

        self::assertTrue($this->repo->setHandled('korbo26', $id, true, 'Lung', $this->at('2026-07-15 11:30:00')));
        $handled = $this->only('korbo26');
        self::assertTrue($handled->handled);
        self::assertSame('2026-07-15 11:30:00', $handled->handledAt);
        self::assertSame('Lung', $handled->handledBy);
        self::assertSame(0, $this->repo->countUnhandled('korbo26'));

        self::assertTrue($this->repo->setHandled('korbo26', $id, false, 'Lung'));
        $undone = $this->only('korbo26');
        self::assertFalse($undone->handled);
        self::assertNull($undone->handledAt);
        self::assertNull($undone->handledBy);
        self::assertSame([$id], self::ids($this->repo->unhandled('korbo26')));
    }

    public function testSetHandledWithoutAnInstantStampsTheClock(): void
    {
        $id = $this->repo->add('korbo26', 42, null, Kind::Help, 'text', null);
        $this->clock->set($this->at('2026-07-15 12:00:00'));

        $this->repo->setHandled('korbo26', $id, true, 'Lung');

        self::assertSame('2026-07-15 12:00:00', $this->only('korbo26')->handledAt);
    }

    /** Two organisers marking the same message: the first mark stands. */
    public function testSetHandledTwiceKeepsTheFirstSignature(): void
    {
        $id = $this->repo->add('korbo26', 42, null, Kind::Help, 'text', null);
        $this->repo->setHandled('korbo26', $id, true, 'První', $this->at('2026-07-15 11:00:00'));

        self::assertTrue($this->repo->setHandled('korbo26', $id, true, 'Druhý', $this->at('2026-07-15 11:05:00')));

        $message = $this->only('korbo26');
        self::assertTrue($message->handled);
        self::assertSame('První', $message->handledBy);
        self::assertSame('2026-07-15 11:00:00', $message->handledAt);
    }

    public function testSetHandledAndDeleteAreFalseForAnotherEventsId(): void
    {
        $id = $this->repo->add('korbo26', 42, null, Kind::Help, 'text', null);

        self::assertFalse($this->repo->setHandled('obrok27', $id, true, 'Lung'));
        self::assertFalse($this->repo->delete('obrok27', $id));
        self::assertFalse($this->repo->setHandled('korbo26', $id + 1, true, 'Lung'));
        self::assertFalse($this->repo->delete('korbo26', $id + 1));

        $message = $this->only('korbo26');
        self::assertFalse($message->handled);
        self::assertNull($message->handledBy);
    }

    public function testHandledPagePagesNewestFirst(): void
    {
        $ids = [];
        foreach (['08:00', '09:00', '09:30'] as $time) {
            $id = $this->repo->add('korbo26', 42, null, Kind::Idea, 'text ' . $time, null, $this->at('2026-07-15 ' . $time . ':00'));
            $this->repo->setHandled('korbo26', $id, true, 'Lung');
            $ids[] = $id;
        }
        $this->repo->add('korbo26', 42, null, Kind::Idea, 'unhandled', null, $this->at('2026-07-15 09:45:00'));
        $other = $this->repo->add('obrok27', 42, null, Kind::Idea, 'other event', null);
        $this->repo->setHandled('obrok27', $other, true, 'Lung');

        self::assertSame([$ids[2], $ids[1]], self::ids($this->repo->handledPage('korbo26', 1, perPage: 2)));
        self::assertSame([$ids[0]], self::ids($this->repo->handledPage('korbo26', 2, perPage: 2)));
        self::assertSame([], $this->repo->handledPage('korbo26', 3, perPage: 2));
        self::assertSame(3, $this->repo->countHandled('korbo26'));
        self::assertSame(1, $this->repo->countHandled('obrok27'));
    }

    public function testDeleteRemovesTheRow(): void
    {
        $gone = $this->repo->add('korbo26', 42, null, Kind::Help, 'a', null);
        $kept = $this->repo->add('korbo26', 42, null, Kind::Help, 'b', null);

        self::assertTrue($this->repo->delete('korbo26', $gone));

        self::assertSame([$kept], self::ids($this->repo->unhandled('korbo26')));
        self::assertFalse($this->repo->delete('korbo26', $gone), 'a deleted row is gone for good');
    }

    public function testTransactCommitsTheWorkAndAnswersItsResult(): void
    {
        $id = $this->repo->transact(fn(): int => $this->repo->add('korbo26', 42, null, Kind::Help, 'a', null));

        self::assertSame([$id], self::ids($this->repo->unhandled('korbo26')));
    }

    public function testTransactRollsBackOnAThrow(): void
    {
        try {
            $this->repo->transact(function (): never {
                $this->repo->add('korbo26', 42, null, Kind::Help, 'a', null);

                throw new \RuntimeException('failed after the insert');
            });
            self::fail('the throw passes through');
        } catch (\RuntimeException $e) {
            self::assertSame('failed after the insert', $e->getMessage());
        }

        self::assertSame([], $this->repo->unhandled('korbo26'), 'the insert is rolled back');
        // the connection is out of the transaction: a next one begins
        $this->repo->transact(fn(): int => $this->repo->add('korbo26', 42, null, Kind::Help, 'b', null));
        self::assertCount(1, $this->repo->unhandled('korbo26'));
    }

    /** BEGIN IMMEDIATE: another connection cannot write from the first statement on, before anything was written. */
    public function testTransactHoldsTheWriteLockFromItsStart(): void
    {
        $path = sys_get_temp_dir() . '/inbox-lock-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $mine = Database::open($path);
            (new Migrator())->migrate($mine);
            $other = Database::open($path);
            $other->exec('PRAGMA busy_timeout=0');
            $repo = new InboxRepository($mine, $this->clock);

            $refused = $repo->transact(static function () use ($other): bool {
                try {
                    $other->exec('BEGIN IMMEDIATE');
                    $other->exec('ROLLBACK');

                    return false;
                } catch (\PDOException) {
                    return true;
                }
            });

            self::assertTrue($refused, 'the other connection waits for the lock');
        } finally {
            unset($mine, $other, $repo);
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
        }
    }
}
