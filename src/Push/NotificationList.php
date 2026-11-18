<?php

declare(strict_types=1);

namespace App\Push;

use App\Time\Zone;

/**
 * The sent notifications as readers see them: the ones meant for this reader, each with its
 * time relative to now, grouped by the Prague calendar day it was sent on. Informace lists
 * them all, the home screen today's.
 */
final class NotificationList
{
    /** Short Czech weekday names for a day heading, indexed by format('N'). */
    private const array SHORT_WEEKDAYS = [1 => 'Po', 2 => 'Út', 3 => 'St', 4 => 'Čt', 5 => 'Pá', 6 => 'So', 7 => 'Ne'];

    /** MessageRepository's stamp: a wall-clock time in the process's zone, which Kernel sets to Prague. */
    private const string STAMP = 'Y-m-d H:i:s';

    /**
     * A message for everyone is every reader's; a message for one programme only a reader
     * registered for it. Order is kept: MessageRepository::visible() is newest first.
     *
     * @param list<Message> $messages MessageRepository::visible() rows, newest first
     * @param array<int, string> $mine programme id => name, of the reader's registrations
     * `when` is the server's relative time and `at` the same instant as DATE_ATOM, from which
     * www/js/ui/reltime.js redraws `when` on the device clock: a copy read later or offline must not
     * keep saying "právě teď".
     *
     * @return list<Notification>
     */
    public static function items(array $messages, array $mine, \DateTimeImmutable $now): array
    {
        $items = [];
        foreach ($messages as $message) {
            $programmeId = $message->programmeId;
            if ($programmeId !== null && !array_key_exists($programmeId, $mine)) {
                continue;
            }
            $sentAt = self::sentAt($message->sentAt);
            if ($sentAt === null) {
                // the repository writes every stamp itself, so this is a row edited by hand;
                // without a time it belongs to no day group
                continue;
            }
            $items[] = new Notification(
                id: $message->id,
                when: self::relative($sentAt, $now),
                at: $sentAt->format(\DATE_ATOM),
                sentAt: $sentAt,
                title: $message->title,
                body: $message->body,
                programme: $programmeId === null
                    ? null
                    : ($mine[$programmeId] !== '' ? $mine[$programmeId] : $message->targetLabel),
            );
        }

        return $items;
    }

    /** A MessageRepository stamp as an instant in Prague, or null when it is not one. */
    public static function sentAt(string $stamp): ?\DateTimeImmutable
    {
        $at = \DateTimeImmutable::createFromFormat('!' . self::STAMP, $stamp, Zone::prague());

        return $at === false || $at->format(self::STAMP) !== $stamp ? null : $at;
    }

    /**
     * When a message was sent, as the reader reads it. Minutes win over the calendar: under
     * an hour it is always "před N min", even across midnight; then "před N h" the same
     * Prague day, "včera H:i" the day before, and a date before that. www/js/ui/reltime.js
     * (relativeTime()) repeats these bands on the device clock; change both together.
     */
    public static function relative(\DateTimeImmutable $at, \DateTimeImmutable $now): string
    {
        $zone = Zone::prague();
        $at = $at->setTimezone($zone);
        $now = $now->setTimezone($zone);
        $seconds = $now->getTimestamp() - $at->getTimestamp();

        // a clock a little ahead of the reader's is still "now", not the future
        if ($seconds < 60) {
            return 'právě teď';
        }
        if ($seconds < 3600) {
            return sprintf('před %d min', intdiv($seconds, 60));
        }
        if ($at->format('Y-m-d') === $now->format('Y-m-d')) {
            return sprintf('před %d h', intdiv($seconds, 3600));
        }
        // noon, so a DST change cannot move the day before onto another date
        if ($at->format('Y-m-d') === $now->setTime(12, 0)->modify('-1 day')->format('Y-m-d')) {
            return 'včera ' . $at->format('H:i');
        }

        return $at->format('j. n. H:i');
    }

    /**
     * Items grouped by the Prague day they were sent on, in the order they come (newest
     * first). The key is the morph's handle on a group: a new day arrives at the top.
     *
     * @param list<Notification> $items items()
     * @return list<NotificationGroup>
     */
    public static function groups(array $items): array
    {
        $zone = Zone::prague();
        $labels = [];
        $byDay = [];
        foreach ($items as $item) {
            $day = $item->sentAt->setTimezone($zone);
            $key = 'news-' . $day->format('Ymd');
            $labels[$key] ??= self::SHORT_WEEKDAYS[(int) $day->format('N')] . ' ' . $day->format('j. n.');
            $byDay[$key][] = $item;
        }

        $groups = [];
        foreach ($byDay as $key => $dayItems) {
            $groups[] = new NotificationGroup($key, $labels[$key], $dayItems);
        }

        return $groups;
    }

    /**
     * The items sent on now's Prague calendar day.
     *
     * @param list<Notification> $items items()
     * @return list<Notification>
     */
    public static function today(array $items, \DateTimeImmutable $now): array
    {
        $zone = Zone::prague();
        $today = $now->setTimezone($zone)->format('Y-m-d');

        return array_values(array_filter(
            $items,
            static fn(Notification $item): bool => $item->sentAt->setTimezone($zone)->format('Y-m-d') === $today,
        ));
    }
}
