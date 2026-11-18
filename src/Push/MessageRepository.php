<?php

declare(strict_types=1);

namespace App\Push;

use App\Storage\Row;
use App\Time\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Every message an organiser sent, kept forever. Informace and a programme's sheet
 * are built from it, which is why a message can be hidden — never deleted, and the last
 * hide or show records who did it and when.
 */
final class MessageRepository
{
    /** The schema is the Migrator's. */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function add(
        string $event,
        ?int $programmeId,
        string $targetLabel,
        string $title,
        string $body,
        string $signature,
        int $sent,
        int $removed,
        ?int $unreached,
        ?\DateTimeImmutable $at = null,
        ?int $failed = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO messages (event, sent_at, programme_id, target_label, title, body, signature, sent, removed, failed, unreached)
             VALUES (:event, :sent_at, :programme_id, :target_label, :title, :body, :signature, :sent, :removed, :failed, :unreached)',
        );
        $statement->execute([
            'event' => $event,
            'sent_at' => $this->stamp($at),
            'programme_id' => $programmeId,
            'target_label' => $targetLabel,
            'title' => $title,
            'body' => $body,
            'signature' => $signature,
            'sent' => $sent,
            'removed' => $removed,
            'failed' => $failed,
            'unreached' => $unreached,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Logs a message before it is sent: its counts stay NULL until finish(), so a send that
     * dies half-way still leaves the message on Informace and in the admin log.
     */
    public function begin(
        string $event,
        ?int $programmeId,
        string $targetLabel,
        string $title,
        string $body,
        string $signature,
        ?\DateTimeImmutable $at = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO messages (event, sent_at, programme_id, target_label, title, body, signature, sent, removed, failed, unreached)
             VALUES (:event, :sent_at, :programme_id, :target_label, :title, :body, :signature, NULL, NULL, NULL, NULL)',
        );
        $statement->execute([
            'event' => $event,
            'sent_at' => $this->stamp($at),
            'programme_id' => $programmeId,
            'target_label' => $targetLabel,
            'title' => $title,
            'body' => $body,
            'signature' => $signature,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Records what the send of a begun message came to; another event's id changes nothing. */
    public function finish(string $event, int $id, int $sent, int $removed, int $failed, ?int $unreached): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE messages SET sent = :sent, removed = :removed, failed = :failed, unreached = :unreached WHERE id = :id AND event = :event',
        );
        $statement->execute(['sent' => $sent, 'removed' => $removed, 'failed' => $failed, 'unreached' => $unreached, 'id' => $id, 'event' => $event]);
    }

    /** @return list<Message> newest first; $page is 1-based */
    public function page(string $event, int $page, int $perPage = 50): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE event = :event ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $statement->bindValue('event', $event);
        $statement->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $page - 1) * $perPage, \PDO::PARAM_INT);
        $statement->execute();

        return array_map(Message::fromRow(...), Row::all($statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function count(string $event): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE event = :event');
        $statement->execute(['event' => $event]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<Message> the messages readers see, newest first */
    public function visible(string $event): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE event = :event AND hidden = 0 ORDER BY id DESC');
        $statement->execute(['event' => $event]);

        return array_map(Message::fromRow(...), Row::all($statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /** @return bool false when the event has no message with that id */
    public function setHidden(string $event, int $id, bool $hidden, string $by, ?\DateTimeImmutable $at = null): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE messages SET hidden = :hidden, toggled_at = :at, toggled_by = :by WHERE id = :id AND event = :event',
        );
        $statement->execute(['hidden' => $hidden ? 1 : 0, 'at' => $this->stamp($at), 'by' => $by, 'id' => $id, 'event' => $event]);

        return $statement->rowCount() === 1;
    }

    /** The instant given, or now by the clock, as the local time the column holds. */
    private function stamp(?\DateTimeImmutable $at): string
    {
        return ($at ?? $this->clock->now())->format('Y-m-d H:i:s');
    }
}
