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
            'sent' => 3, 'removed' => 1, 'unreached' => 5, 'hidden' => false, 'toggledAt' => null, 'toggledBy' => null,
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
}
