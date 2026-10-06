<?php

declare(strict_types=1);

namespace App\Push;

/**
 * Every message an organiser sent, kept forever. The News screen and a programme's sheet
 * are built from it, which is why a message can be hidden — never deleted, and the last
 * hide or show records who did it and when.
 */
final class MessageRepository
{
    /** The schema is the Migrator's. */
    public function __construct(private readonly \PDO $pdo)
    {
    }

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
             VALUES (:event, :sent_at, :programme_id, :target_label, :title, :body, :signature, :sent, :removed, :failed, :unreached)'
        );
        $statement->execute([
            'event' => $event,
            'sent_at' => self::stamp($at),
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
     * dies half-way still leaves the message on News and in the admin log.
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
             VALUES (:event, :sent_at, :programme_id, :target_label, :title, :body, :signature, NULL, NULL, NULL, NULL)'
        );
        $statement->execute([
            'event' => $event,
            'sent_at' => self::stamp($at),
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
            'UPDATE messages SET sent = :sent, removed = :removed, failed = :failed, unreached = :unreached WHERE id = :id AND event = :event'
        );
        $statement->execute(['sent' => $sent, 'removed' => $removed, 'failed' => $failed, 'unreached' => $unreached, 'id' => $id, 'event' => $event]);
    }

    /** @return list<array> newest first; $page is 1-based */
    public function page(string $event, int $page, int $perPage = 50): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE event = :event ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $statement->bindValue('event', $event);
        $statement->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $page - 1) * $perPage, \PDO::PARAM_INT);
        $statement->execute();

        return array_map(self::row(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function count(string $event): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE event = :event');
        $statement->execute(['event' => $event]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array> the messages readers see, newest first */
    public function visible(string $event): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE event = :event AND hidden = 0 ORDER BY id DESC');
        $statement->execute(['event' => $event]);

        return array_map(self::row(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return bool false when the event has no message with that id */
    public function setHidden(string $event, int $id, bool $hidden, string $by, ?\DateTimeImmutable $at = null): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE messages SET hidden = :hidden, toggled_at = :at, toggled_by = :by WHERE id = :id AND event = :event'
        );
        $statement->execute(['hidden' => $hidden ? 1 : 0, 'at' => self::stamp($at), 'by' => $by, 'id' => $id, 'event' => $event]);

        return $statement->rowCount() === 1;
    }

    private static function stamp(?\DateTimeImmutable $at): string
    {
        return ($at ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private static function row(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'event' => $r['event'],
            'sentAt' => $r['sent_at'],
            'programmeId' => $r['programme_id'] === null ? null : (int) $r['programme_id'],
            'targetLabel' => $r['target_label'],
            'title' => $r['title'],
            'body' => $r['body'],
            'signature' => $r['signature'],
            // NULL while the send runs, and for good when it died
            'sent' => $r['sent'] === null ? null : (int) $r['sent'],
            'removed' => $r['removed'] === null ? null : (int) $r['removed'],
            'failed' => $r['failed'] === null ? null : (int) $r['failed'],
            'unreached' => $r['unreached'] === null ? null : (int) $r['unreached'],
            'hidden' => (bool) $r['hidden'],
            'toggledAt' => $r['toggled_at'],
            'toggledBy' => $r['toggled_by'],
        ];
    }
}
