<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\MessageRepository;
use App\Storage\Database;
use App\Storage\Migrator;
use PHPUnit\Framework\TestCase;

final class MessageRepositoryTest extends TestCase
{
    private function repo(): MessageRepository
    {
        $pdo = Database::open(':memory:');
        (new Migrator())->migrate($pdo);

        return new MessageRepository($pdo);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Prague'));
    }

    public function testAMessageIsStoredWithEveryField(): void
    {
        $repo = $this->repo();
        $id = $repo->add('korbo26', 42, 'Vodní hrátky', 'Změna', "Řádek 1\nŘádek 2", 'Lung', 3, 1, 5, $this->at('2026-10-02 14:05:00'));

        self::assertSame([[
            'id' => $id, 'event' => 'korbo26', 'sentAt' => '2026-10-02 14:05:00', 'programmeId' => 42,
            'targetLabel' => 'Vodní hrátky', 'title' => 'Změna', 'body' => "Řádek 1\nŘádek 2", 'signature' => 'Lung',
            'sent' => 3, 'removed' => 1, 'failed' => null, 'unreached' => 5, 'hidden' => false, 'toggledAt' => null, 'toggledBy' => null,
        ]], $repo->page('korbo26', 1));
    }

    public function testPagesAreNewestFirstAndPerEvent(): void
    {
        $repo = $this->repo();
        foreach (range(1, 3) as $n) {
            $repo->add('korbo26', null, 'Všem', 'Zpráva ' . $n, 'text', 'Lung', 0, 0, null);
        }
        $repo->add('obrok27', null, 'Všem', 'Jinde', 'text', 'Lung', 0, 0, null);

        self::assertSame(['Zpráva 3', 'Zpráva 2'], array_column($repo->page('korbo26', 1, 2), 'title'));
        self::assertSame(['Zpráva 1'], array_column($repo->page('korbo26', 2, 2), 'title'));
        self::assertSame(3, $repo->count('korbo26'));
    }

    public function testHidingIsReversibleRecordedAndScopedToTheEvent(): void
    {
        $repo = $this->repo();
        $id = $repo->add('korbo26', null, 'Všem', 'Překlep', 'text', 'Lung', 0, 0, null);

        self::assertFalse($repo->setHidden('obrok27', $id, true, 'Cizí'));
        self::assertTrue($repo->setHidden('korbo26', $id, true, 'Jana', $this->at('2026-10-02 15:00:00')));
        self::assertSame([], $repo->visible('korbo26'));
        $row = $repo->page('korbo26', 1)[0];
        self::assertTrue($row['hidden']);
        self::assertSame('Jana', $row['toggledBy']);
        self::assertSame('2026-10-02 15:00:00', $row['toggledAt']);

        self::assertTrue($repo->setHidden('korbo26', $id, false, 'Lung'));
        self::assertSame(['Překlep'], array_column($repo->visible('korbo26'), 'title'));
        self::assertSame('Lung', $repo->page('korbo26', 1)[0]['toggledBy']);
    }

    public function testAddRecordsTheFailedCountWhenGiven(): void
    {
        $repo = $this->repo();
        $repo->add('korbo26', null, 'Všem', 'Změna', 'text', 'Lung', 3, 1, null, failed: 2);

        self::assertSame(2, $repo->page('korbo26', 1)[0]['failed']);
    }

    public function testBeginLogsTheMessageWithoutCounts(): void
    {
        $repo = $this->repo();
        $id = $repo->begin('korbo26', 42, 'Vodní hrátky', 'Změna', 'text', 'Lung', $this->at('2026-10-02 14:05:00'));

        $row = $repo->page('korbo26', 1)[0];
        self::assertSame($id, $row['id']);
        self::assertSame(['2026-10-02 14:05:00', 42, 'Vodní hrátky', 'Změna', 'Lung'], [$row['sentAt'], $row['programmeId'], $row['targetLabel'], $row['title'], $row['signature']]);
        self::assertSame([null, null, null, null], [$row['sent'], $row['removed'], $row['failed'], $row['unreached']]);
        self::assertSame(['Změna'], array_column($repo->visible('korbo26'), 'title'), 'readers see it before the send returns');
    }

    public function testFinishFillsTheCounts(): void
    {
        $repo = $this->repo();
        $id = $repo->begin('korbo26', null, 'Všem', 'Změna', 'text', 'Lung');

        $repo->finish('korbo26', $id, 3, 1, 2, 4);

        $row = $repo->page('korbo26', 1)[0];
        self::assertSame([3, 1, 2, 4], [$row['sent'], $row['removed'], $row['failed'], $row['unreached']]);
    }

    public function testFinishWithAnotherEventChangesNothing(): void
    {
        $repo = $this->repo();
        $id = $repo->begin('korbo26', null, 'Všem', 'Změna', 'text', 'Lung');

        $repo->finish('obrok27', $id, 3, 1, 2, 4);

        $row = $repo->page('korbo26', 1)[0];
        self::assertSame([null, null, null, null], [$row['sent'], $row['removed'], $row['failed'], $row['unreached']]);
    }
}
