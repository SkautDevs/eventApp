<?php

declare(strict_types=1);

namespace App\Inbox;

use App\Storage\Row;
use App\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * The messages participants sent to the organisers of an event. Every query is scoped to
 * one event, so an id of another event reads as no message at all. Rows stay until an
 * organiser deletes them.
 */
final class InboxRepository
{
    /** The schema is the Migrator's. */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * Runs $work in one BEGIN IMMEDIATE transaction and answers its result: the write lock
     * is taken before the first read, so the checks $work makes still hold when it writes.
     * A throw rolls it back and passes through.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transact(callable $work): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\PDOException) {
                // SQLite may already have rolled back on its own (disk full, I/O error)
            }
            throw $e;
        }
    }

    public function add(
        string $event,
        int $participantId,
        ?string $nickname,
        Kind $kind,
        string $body,
        ?string $contact,
        ?\DateTimeImmutable $at = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO inbox_messages (event, sent_at, participant_id, nickname, kind, body, contact)
             VALUES (:event, :sent_at, :participant_id, :nickname, :kind, :body, :contact)',
        );
        $statement->execute([
            'event' => $event,
            'sent_at' => $this->stamp($at),
            'participant_id' => $participantId,
            'nickname' => $nickname,
            'kind' => $kind->value,
            'body' => $body,
            'contact' => $contact,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** The participant's messages sent after $since, the throttle's count. */
    public function countSince(string $event, int $participantId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM inbox_messages WHERE event = :event AND participant_id = :participant_id AND sent_at > :since',
        );
        $statement->execute(['event' => $event, 'participant_id' => $participantId, 'since' => $this->stamp($since)]);

        return Row::toInt($statement->fetchColumn());
    }

    /** Whether the participant sent this kind and text after $since: a repeat of a send. */
    public function existsSince(string $event, int $participantId, Kind $kind, string $body, \DateTimeImmutable $since): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM inbox_messages
             WHERE event = :event AND participant_id = :participant_id AND kind = :kind AND body = :body AND sent_at > :since
             LIMIT 1',
        );
        $statement->execute([
            'event' => $event,
            'participant_id' => $participantId,
            'kind' => $kind->value,
            'body' => $body,
            'since' => $this->stamp($since),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<InboxMessage> newest first */
    public function unhandled(string $event): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM inbox_messages WHERE event = :event AND handled = 0 ORDER BY sent_at DESC, id DESC',
        );
        $statement->execute(['event' => $event]);

        return array_map(InboxMessage::fromRow(...), Row::all($statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function countUnhandled(string $event): int
    {
        return $this->count($event, false);
    }

    /** @return list<InboxMessage> newest first; $page is 1-based */
    public function handledPage(string $event, int $page, int $perPage = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM inbox_messages WHERE event = :event AND handled = 1 ORDER BY sent_at DESC, id DESC LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue('event', $event);
        $statement->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $page - 1) * $perPage, \PDO::PARAM_INT);
        $statement->execute();

        return array_map(InboxMessage::fromRow(...), Row::all($statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function countHandled(string $event): int
    {
        return $this->count($event, true);
    }

    /**
     * Marks a message handled, recording who and when, or returns it to the unhandled ones,
     * clearing both. Marking a message that is already handled keeps the first mark: two
     * organisers who handle it at once do not overwrite each other.
     *
     * @return bool false when the event has no message with that id
     */
    public function setHandled(string $event, int $id, bool $handled, string $by, ?\DateTimeImmutable $at = null): bool
    {
        if ($handled) {
            // SQLite evaluates every SET expression against the row as it was before the update
            $statement = $this->pdo->prepare(
                'UPDATE inbox_messages SET
                    handled_at = CASE WHEN handled = 1 THEN handled_at ELSE :at END,
                    handled_by = CASE WHEN handled = 1 THEN handled_by ELSE :by END,
                    handled = 1
                 WHERE id = :id AND event = :event',
            );
            $statement->execute(['at' => $this->stamp($at), 'by' => $by, 'id' => $id, 'event' => $event]);
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE inbox_messages SET handled = 0, handled_at = NULL, handled_by = NULL WHERE id = :id AND event = :event',
            );
            $statement->execute(['id' => $id, 'event' => $event]);
        }

        return $statement->rowCount() === 1;
    }

    /** @return bool false when the event has no message with that id */
    public function delete(string $event, int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM inbox_messages WHERE id = :id AND event = :event');
        $statement->execute(['id' => $id, 'event' => $event]);

        return $statement->rowCount() === 1;
    }

    private function count(string $event, bool $handled): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM inbox_messages WHERE event = :event AND handled = :handled');
        $statement->execute(['event' => $event, 'handled' => $handled ? 1 : 0]);

        return Row::toInt($statement->fetchColumn());
    }

    /** The instant given, or now by the clock, as the local time the column holds. */
    private function stamp(?\DateTimeImmutable $at): string
    {
        return ($at ?? $this->clock->now())->format('Y-m-d H:i:s');
    }
}
