<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Inbox\InboxMessage;
use App\Inbox\InboxRepository;
use App\Inbox\InboxWriter;
use App\Inbox\Kind;
use App\Inbox\Submission;
use App\Inbox\WriteOutcome;
use App\Storage\Migrator;
use PHPUnit\Framework\TestCase;
use Tests\FixedClock;
use Tests\Functional\AppTestCase;

final class InboxWriterTest extends TestCase
{
    private FixedClock $clock;

    private InboxRepository $repo;

    private InboxWriter $writer;

    protected function setUp(): void
    {
        $this->clock = new FixedClock($this->at('2026-07-15 10:00:00'));
        $this->repo = new InboxRepository(AppTestCase::memoryDb(), $this->clock);
        $this->writer = new InboxWriter($this->repo, $this->clock);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Prague'));
    }

    private function later(string $interval): void
    {
        $this->clock->set($this->clock->now()->add(new \DateInterval($interval)));
    }

    private static function submission(Kind $kind, string $body, string $contact = ''): Submission
    {
        return new Submission($kind, $body, $contact);
    }

    private function send(Submission $submission, int $participantId = 42, string $event = 'korbo26'): WriteOutcome
    {
        return $this->writer->write($event, $participantId, 'Kája', $submission);
    }

    /** @return list<InboxMessage> */
    private function rows(string $event = 'korbo26'): array
    {
        return $this->repo->unhandled($event);
    }

    public function testAValidSubmissionIsSaved(): void
    {
        self::assertSame(WriteOutcome::Saved, $this->writer->write('korbo26', 42, '  Kája ', self::submission(Kind::Help, 'Neteče sprcha', '777 123 456')));
        self::assertSame(WriteOutcome::Saved, $this->writer->write('korbo26', 43, '  ', self::submission(Kind::Idea, 'Víc kafe')));

        $rows = $this->rows();
        self::assertCount(2, $rows);
        [$idea, $help] = $rows;
        self::assertSame(42, $help->participantId);
        self::assertSame('Kája', $help->nickname);
        self::assertSame(Kind::Help, $help->kind);
        self::assertSame('Neteče sprcha', $help->body);
        self::assertSame('777 123 456', $help->contact);
        self::assertSame('2026-07-15 10:00:00', $help->sentAt);
        self::assertNull($idea->nickname);
        self::assertNull($idea->contact);
    }

    public function testANullNicknameIsStoredAsNull(): void
    {
        $this->writer->write('korbo26', 42, null, self::submission(Kind::Help, 'text'));

        self::assertNull($this->rows()[0]->nickname);
    }

    public function testTheSameKindAndTextWithinAMinuteIsARepeat(): void
    {
        $this->send(self::submission(Kind::Help, 'Neteče sprcha'));
        $this->later('PT59S');

        self::assertSame(WriteOutcome::Repeated, $this->send(self::submission(Kind::Help, 'Neteče sprcha')));
        self::assertCount(1, $this->rows());
    }

    public function testTheSameTextAfterAMinuteIsSavedAgain(): void
    {
        $this->send(self::submission(Kind::Help, 'Neteče sprcha'));
        $this->later('PT61S');

        self::assertSame(WriteOutcome::Saved, $this->send(self::submission(Kind::Help, 'Neteče sprcha')));
        self::assertCount(2, $this->rows());
    }

    public function testTheSameTextOfTheOtherKindIsNotARepeat(): void
    {
        $this->send(self::submission(Kind::Help, 'Sprcha'));

        self::assertSame(WriteOutcome::Saved, $this->send(self::submission(Kind::Idea, 'Sprcha')));
        self::assertCount(2, $this->rows());
    }

    public function testTheSixthMessageInAnHourIsThrottled(): void
    {
        for ($i = 1; $i <= InboxWriter::PER_HOUR; ++$i) {
            self::assertSame(WriteOutcome::Saved, $this->send(self::submission(Kind::Help, 'Zpráva ' . $i)));
            $this->later('PT5M');
        }

        self::assertSame(WriteOutcome::Throttled, $this->send(self::submission(Kind::Help, 'Zpráva 6')));
        self::assertCount(5, $this->rows());
    }

    public function testTheThrottleOpensWhenTheOldestIsOverAnHourOld(): void
    {
        for ($i = 1; $i <= InboxWriter::PER_HOUR; ++$i) {
            $this->send(self::submission(Kind::Help, 'Zpráva ' . $i));
            $this->later('PT1M');
        }
        // the oldest was sent at 10:00:00, the clock is at 10:05:00
        $this->clock->set($this->at('2026-07-15 10:59:59'));
        self::assertSame(WriteOutcome::Throttled, $this->send(self::submission(Kind::Help, 'Zpráva 6')));

        $this->clock->set($this->at('2026-07-15 11:00:01'));
        self::assertSame(WriteOutcome::Saved, $this->send(self::submission(Kind::Help, 'Zpráva 6')));
        self::assertCount(6, $this->rows());
    }

    public function testARepeatOfTheFifthMessageIsStillARepeat(): void
    {
        for ($i = 1; $i <= InboxWriter::PER_HOUR; ++$i) {
            $this->send(self::submission(Kind::Help, 'Zpráva ' . $i));
        }
        $this->later('PT10S');

        self::assertSame(WriteOutcome::Repeated, $this->send(self::submission(Kind::Help, 'Zpráva 5')));
        self::assertCount(5, $this->rows());
    }

    public function testAnotherParticipantOrEventIsNotCounted(): void
    {
        for ($i = 1; $i <= InboxWriter::PER_HOUR; ++$i) {
            $this->send(self::submission(Kind::Help, 'Zpráva ' . $i));
        }

        self::assertSame(WriteOutcome::Saved, $this->send(self::submission(Kind::Help, 'Zpráva 5'), participantId: 43));
        self::assertSame(WriteOutcome::Saved, $this->send(self::submission(Kind::Help, 'Zpráva 5'), event: 'obrok27'));
        self::assertCount(6, $this->rows());
        self::assertCount(1, $this->rows('obrok27'));
    }

    /** The repeat check, the count and the insert run under one write lock, so two sends at once cannot both pass the checks. */
    public function testTheChecksAndTheInsertRunInOneImmediateTransaction(): void
    {
        $pdo = new RecordingPdo('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        (new Migrator())->migrate($pdo);
        $writer = new InboxWriter(new InboxRepository($pdo, $this->clock), $this->clock);

        $pdo->statements = [];
        self::assertSame(WriteOutcome::Saved, $writer->write('korbo26', 42, null, self::submission(Kind::Help, 'Neteče sprcha')));
        self::assertSame(['BEGIN IMMEDIATE', 'SELECT', 'SELECT', 'INSERT', 'COMMIT'], self::verbs($pdo->statements));

        $pdo->statements = [];
        self::assertSame(WriteOutcome::Repeated, $writer->write('korbo26', 42, null, self::submission(Kind::Help, 'Neteče sprcha')));
        self::assertSame(['BEGIN IMMEDIATE', 'SELECT', 'COMMIT'], self::verbs($pdo->statements));
    }

    /**
     * Each statement's first word, BEGIN IMMEDIATE whole.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private static function verbs(array $statements): array
    {
        return array_map(
            static fn(string $sql): string => str_starts_with($sql, 'BEGIN') ? $sql : strtoupper((string) strtok(ltrim($sql), " \n")),
            $statements,
        );
    }
}

/** A PDO that records every statement it prepares or executes. */
final class RecordingPdo extends \PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }
}
