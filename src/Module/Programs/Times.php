<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Program\Programme;
use App\Time\Zone;

/**
 * How the Program screen reads and prints a programme's times: a placed programme's start
 * and end (start(), end()), its day (dayOf()), the day label ("čt 30. 5."), the range of a
 * programme ("8:00–12:00", or both days for a run past midnight) and an instant handed to
 * the client. A programme's times are Prague wall-clock DateTimeImmutables and every one
 * is printed with format() on them, so the output is the event's own whatever zone the
 * process runs in; at() turns a timeline instant back into one. Pure.
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
     * The full names are HomeModel::WEEKDAYS, which TwigFactory's dateToCzechDayName filter
     * reads; the pager needs the short forms, which do not exist anywhere else.
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
    public static function timeRange(Programme $program): string
    {
        $segments = TimelineBuilder::segments($program);
        if (count($segments) > 1) {
            return self::whenLabel($program);
        }

        return self::start($program)->format(self::CLOCK) . '–' . self::end($program)->format(self::CLOCK);
    }

    /**
     * e.g. "čt 30. 5. 8:00–12:00", or with both days when the programme runs past midnight:
     * "čt 30. 5. 22:00 – pá 31. 5. 1:30" — spaced there, because the dash joins two dates.
     */
    public static function whenLabel(Programme $program): string
    {
        $start = self::start($program);
        $end = self::end($program);
        $startDay = self::dayLabel($start->format('Y-m-d'));
        $endDay = self::dayLabel($end->format('Y-m-d'));

        return $startDay === $endDay
            ? $startDay . ' ' . $start->format(self::CLOCK) . '–' . $end->format(self::CLOCK)
            : $startDay . ' ' . $start->format(self::CLOCK) . ' – ' . $endDay . ' ' . $end->format(self::CLOCK);
    }

    public static function dayOf(Programme $program): string
    {
        return self::start($program)->format('Y-m-d');
    }

    /**
     * The start of a programme the screen places. ScheduleBuilder::withParsableDates() is
     * what keeps a programme without both dates off the screen, so one reaching here
     * without a start is a bug, not data.
     */
    public static function start(Programme $program): \DateTimeImmutable
    {
        return $program->start ?? throw new \LogicException('A programme without a start is never placed on the screen');
    }

    /** The end of a placed programme; see start(). */
    public static function end(Programme $program): \DateTimeImmutable
    {
        return $program->end ?? throw new \LogicException('A programme without an end is never placed on the screen');
    }

    /** A timeline instant (a Unix timestamp) as the Prague time it is. */
    public static function at(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(Zone::prague());
    }

    /** A time as the instant handed to the client: DATE_ATOM in Europe/Prague, with its offset. */
    public static function instant(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(Zone::prague())->format(DATE_ATOM);
    }
}
