<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Time\Zone;

/**
 * How the Program screen reads and prints a programme's times: the provider's
 * `{'date': ...}` values as timestamps (parse(), ts()), a programme's day (dayOf()), the
 * day label ("čt 30. 5."), the range of a programme ("8:00–12:00", or both days for a run
 * past midnight) and an instant handed to the client. Every timestamp is a wall-clock time
 * read in the process's default zone; only instant() names the event's own. Pure.
 */
final class Times
{
    /**
     * The one way a time of day is printed in a time range — the sheet's Kdy, the toast,
     * the list rows and the timeline card's name: no leading zero on the hour ("8:00"),
     * as Czech writes it, and Domů's next-programme cards (HomeModel) the same way. The
     * hour ruler keeps "08:00", a fixed-width instrument label.
     */
    public const string CLOCK = 'G:i';

    /**
     * Czech weekday abbreviations for the pager label, indexed by the ISO weekday number (format 'N').
     * Kernel carries the full names for its dateToCzechDayName filter; the pager
     * needs the short forms, which do not exist anywhere else.
     */
    public const array SHORT_DAY_NAMES = [1 => 'po', 2 => 'út', 3 => 'st', 4 => 'čt', 5 => 'pá', 6 => 'so', 7 => 'ne'];

    /** e.g. "čt 30. 5." */
    public static function dayLabel(string $day): string
    {
        $date = new \DateTimeImmutable($day);

        return self::SHORT_DAY_NAMES[(int) $date->format('N')] . ' ' . $date->format('j. n.');
    }

    /**
     * e.g. "8:00–12:00"; a programme that runs into another day names both days, the
     * way the sheet does. Ending at midnight is ending the evening it started on.
     */
    public static function timeRange(array $program): string
    {
        $segments = TimelineBuilder::segments($program);
        if (count($segments) > 1) {
            return self::whenLabel($program);
        }

        return date(self::CLOCK, self::ts($program['start'])) . '–' . date(self::CLOCK, self::ts($program['end']));
    }

    /**
     * e.g. "čt 30. 5. 8:00–12:00", or with both days when the programme runs past midnight:
     * "čt 30. 5. 22:00 – pá 31. 5. 1:30" — spaced there, because the dash joins two dates.
     */
    public static function whenLabel(array $program): string
    {
        $start = self::ts($program['start']);
        $end = self::ts($program['end']);
        $startDay = self::dayLabel(date('Y-m-d', $start));
        $endDay = self::dayLabel(date('Y-m-d', $end));

        return $startDay === $endDay
            ? $startDay . ' ' . date(self::CLOCK, $start) . '–' . date(self::CLOCK, $end)
            : $startDay . ' ' . date(self::CLOCK, $start) . ' – ' . $endDay . ' ' . date(self::CLOCK, $end);
    }

    public static function dayOf(array $program): string
    {
        return date('Y-m-d', self::ts($program['start']));
    }

    public static function ts(mixed $datetime): int
    {
        return self::parse($datetime) ?? 0;
    }

    /**
     * The timestamp of a `{'date': ...}` value, or null when there is none to read —
     * a null, a missing key, an empty string or anything strtotime() rejects.
     * ScheduleBuilder::withParsableDates() is what keeps such a record off the screen;
     * this is also why ts() takes mixed, so a hand-edited fixture is a dropped row and
     * not a TypeError five frames further in.
     */
    public static function parse(mixed $datetime): ?int
    {
        $date = is_array($datetime) ? ($datetime['date'] ?? null) : null;
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * A wall-clock timestamp (read in the default zone, like every other in here) as the
     * instant it means in the event's zone, DATE_ATOM with its offset. Zone::PRAGUE is the
     * zone the provider's naive datetimes are wall-clock times in. Everything else here
     * reads and prints them in the process's default zone, which is consistent whatever
     * that zone is; only an instant handed to the client has to name the event's own.
     */
    public static function instant(int $timestamp): string
    {
        return (new \DateTimeImmutable(date('Y-m-d H:i:s', $timestamp), Zone::prague()))->format(DATE_ATOM);
    }
}
