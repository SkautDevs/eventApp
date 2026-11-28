<?php

declare(strict_types=1);

namespace App\Inbox;

use Psr\Clock\ClockInterface;

/**
 * Stores a participant's valid message, unless it repeats one they sent within the last
 * minute (REPEAT_WINDOW) or they already sent PER_HOUR messages in the last 60 minutes
 * (THROTTLE_WINDOW). Both are counted from the participant's own rows in this event.
 *
 * The repeat check runs before the throttle: a double tap on the fifth message, or the
 * second POST shell/write.js sends after a 5xx, must answer as the success it was, not as
 * the limit. Both checks and the insert run in one write transaction, so two sends that
 * arrive at once cannot both pass them and store the message twice.
 */
final class InboxWriter
{
    public const int PER_HOUR = 5;

    private const string THROTTLE_WINDOW = 'PT60M';

    private const string REPEAT_WINDOW = 'PT1M';

    public function __construct(
        private readonly InboxRepository $messages,
        private readonly ClockInterface $clock,
    ) {}

    /** `$submission` is valid (Submission::errors() is []). */
    public function write(string $event, int $participantId, ?string $nickname, Submission $submission): WriteOutcome
    {
        $kind = $submission->kind;
        if ($kind === null) {
            throw new \InvalidArgumentException('An inbox submission without a kind cannot be written.');
        }
        $nickname = trim($nickname ?? '');

        return $this->messages->transact(function () use ($event, $participantId, $nickname, $kind, $submission): WriteOutcome {
            $now = $this->clock->now();
            if ($this->messages->existsSince($event, $participantId, $kind, $submission->body, $now->sub(new \DateInterval(self::REPEAT_WINDOW)))) {
                return WriteOutcome::Repeated;
            }
            if ($this->messages->countSince($event, $participantId, $now->sub(new \DateInterval(self::THROTTLE_WINDOW))) >= self::PER_HOUR) {
                return WriteOutcome::Throttled;
            }
            $this->messages->add(
                $event,
                $participantId,
                $nickname === '' ? null : $nickname,
                $kind,
                $submission->body,
                $submission->contact === '' ? null : $submission->contact,
                $now,
            );

            return WriteOutcome::Saved;
        });
    }
}
