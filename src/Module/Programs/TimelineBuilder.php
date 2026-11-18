<?php

declare(strict_types=1);

namespace App\Module\Programs;

use App\Module\Programs\View\Card;
use App\Module\Programs\View\RulerTick;
use App\Module\Programs\View\StageRow;
use App\Module\Programs\View\TimelinePage;
use App\Program\Programme;
use App\Time\Zone;

/**
 * Harmonogram, the timeline: one page per calendar day, each with its hour ruler and one
 * stage row per location, split into tracks so that overlapping programmes never cover
 * each other. A programme running over several days is cut into one piece per day it
 * overlaps (segments()), each clipped to its day. Pure.
 */
final class TimelineBuilder
{
    /** Stage row for programmes that carry no location. */
    private const string NO_LOCATION_LABEL = 'Bez lokace';

    /** Width of one timeline card lane in seconds — the axis is always rounded to whole hours. */
    private const int HOUR = 3600;

    /**
     * Most calendar days one programme is drawn across. Each day's piece is clipped to
     * that day, so no record can stretch a page's axis; this bounds the other way a
     * typo'd `end` from kissj could hurt — a bar on every day of the next ten years.
     * The clip is a drawing decision only: the detail sheet still shows the true end.
     */
    private const int MAX_DRAWN_DAYS = 14;

    /** Shortest bar the grid will draw, in seconds — below this a card is unreadable. */
    private const int MIN_DRAWN_SPAN = 60;

    /**
     * One timeline page: the hour ruler plus one stage row per location, each row split
     * into as many tracks as it takes for overlapping programmes not to cover each other.
     *
     * @param non-empty-list<array{program: Programme, day: string, start: int, end: int}> $segments this day's pieces
     * @param array<int, true> $registeredIds
     */
    public static function buildPage(string $day, array $segments, array $registeredIds, bool $mineRead): TimelinePage
    {
        // the axis is measured against the ends the bars are actually drawn to, so a
        // programme running on past midnight widens this page no further than the day —
        // and a bar widened to the minimum still ends inside the grid
        $starts = array_column($segments, 'start');
        $ends = array_map(self::barEnd(...), $segments);

        $axisStart = intdiv(min($starts), self::HOUR) * self::HOUR;
        $axisEnd = (int) (ceil(max($ends) / self::HOUR) * self::HOUR);
        if ($axisEnd <= $axisStart) {
            $axisEnd = $axisStart + self::HOUR;
        }

        // the last tick sits on the right edge, where its label would hang outside the grid.
        // Offsets are in hours, not percent: the grid is drawn at a fixed pixel-per-hour
        // scale (--hour-width), so every position is calc(--hour-width * offset).
        $ruler = [];
        for ($tick = $axisStart; $tick < $axisEnd; $tick += self::HOUR) {
            $ruler[] = new RulerTick(Times::at($tick)->format('H:i'), self::hours($tick - $axisStart));
        }

        $byLocation = [];
        foreach ($segments as $segment) {
            $location = trim($segment['program']->location ?? '');
            $byLocation[$location][] = $segment;
        }
        // stages read top to bottom in the order their first programme starts; no location goes last.
        // The earliest start is computed once per location rather than inside the comparator,
        // which walked both groups in full on every one of the O(n log n) comparisons.
        $firstStart = array_map(
            static fn(array $items): int => min(array_column($items, 'start')),
            $byLocation,
        );
        uksort($byLocation, static fn($a, $b): int => $firstStart[$a] <=> $firstStart[$b]);

        $rows = [];
        foreach ($byLocation as $location => $items) {
            $rows[] = new StageRow(
                location: $location === '' ? self::NO_LOCATION_LABEL : (string) $location,
                tracks: self::packTracks($items, $axisStart, $registeredIds, $mineRead),
            );
        }
        // the unlocated stage sorts by time like the rest, but it belongs at the bottom
        usort($rows, static fn(StageRow $a, StageRow $b): int => ($a->location === self::NO_LOCATION_LABEL ? 1 : 0)
            <=> ($b->location === self::NO_LOCATION_LABEL ? 1 : 0));

        return new TimelinePage(
            key: 'page-' . (new \DateTimeImmutable($day))->format('Ymd'),
            day: $day,
            label: Times::dayLabel($day),
            hours: count($ruler),
            ruler: $ruler,
            // the axis as instants, so the client can place "now" on it whatever
            // time zone the phone is set to
            axisStart: Times::instant(Times::at($axisStart)),
            axisEnd: Times::instant(Times::at($axisEnd)),
            rows: $rows,
        );
    }

    /**
     * Greedy interval packing: a programme goes into the first track whose previous
     * programme has already ended, so nothing is ever drawn on top of anything else.
     *
     * @param list<array{program: Programme, day: string, start: int, end: int}> $items
     * @param array<int, true> $registeredIds
     * @return list<list<Card>>
     */
    private static function packTracks(array $items, int $axisStart, array $registeredIds, bool $mineRead): array
    {
        usort($items, static fn(array $a, array $b): int => [$a['start'], -$a['end']] <=> [$b['start'], -$b['end']]);

        // packing works on the same end the bar is drawn to, clip and minimum width
        // included: freeing a track at the clipped end while buildCard() widens a shorter
        // programme to MIN_DRAWN_SPAN put the next card underneath that widened bar
        $trackEnds = [];
        $tracks = [];
        foreach ($items as $segment) {
            $index = 0;
            while (isset($trackEnds[$index]) && $trackEnds[$index] > $segment['start']) {
                $index++;
            }
            $trackEnds[$index] = self::barEnd($segment);
            $tracks[$index][] = self::buildCard($segment, $axisStart, $registeredIds, $mineRead);
        }
        ksort($tracks);

        return array_values($tracks);
    }

    /**
     * One card: a programme's piece of one day. Every piece of a programme carries the
     * same id, label and state, so each opens the same sheet and says the same thing.
     *
     * @param array{program: Programme, day: string, start: int, end: int} $segment
     * @param array<int, true> $registeredIds
     */
    private static function buildCard(array $segment, int $axisStart, array $registeredIds, bool $mineRead): Card
    {
        $program = $segment['program'];
        $start = $segment['start'];
        $end = self::barEnd($segment);
        $registered = isset($registeredIds[$program->id]);
        $preregistered = $program->preregistered;

        return new Card(
            id: $program->id,
            name: $program->name,
            // the label keeps the true times, like the sheet — only the bar is clipped
            time: Times::timeRange($program),
            // both in hours from the axis start; the template turns them into pixels
            offset: self::hours($start - $axisStart),
            span: self::hours($end - $start),
            registered: $registered,
            // with the personal list read, the programmes that are not yours step back so
            // yours stand out — never a preregistered one, which kissj leaves out of the
            // personal list and which is, as far as eventApp can tell, everybody's
            dimmed: $mineRead && !$registered && !$preregistered,
        );
    }

    /**
     * A programme cut into one piece per calendar day it overlaps, each clipped to its
     * day: from the later of its start and the day's midnight to the earlier of its end
     * and the next midnight. The day it starts on always has a piece, even a record of
     * no length; any later day only if the programme is still running into it, so an
     * end at exactly midnight leaves no zero-width card on the next day. Only the bars
     * are cut — SheetBuilder::buildDetail() reads the programme's end directly, so the sheet
     * keeps the true time. The pieces' start and end are Unix timestamps; the days are
     * Prague calendar days.
     *
     * @return list<array{program: Programme, day: string, start: int, end: int}>
     */
    public static function segments(Programme $program): array
    {
        $start = Times::start($program)->getTimestamp();
        $end = max(Times::end($program)->getTimestamp(), $start);
        $day = Times::dayOf($program);
        $prague = Zone::prague();

        $segments = [];
        do {
            // by the calendar rather than by 86400 seconds, which a DST change would break
            $dayStart = (new \DateTimeImmutable($day, $prague))->getTimestamp();
            $nextDay = (new \DateTimeImmutable($day . ' +1 day', $prague))->format('Y-m-d');
            $dayEnd = (new \DateTimeImmutable($nextDay, $prague))->getTimestamp();
            $segments[] = ['program' => $program, 'day' => $day, 'start' => max($start, $dayStart), 'end' => min($end, $dayEnd)];
            $day = $nextDay;
        } while ($end > $dayEnd && count($segments) < self::MAX_DRAWN_DAYS);

        return $segments;
    }

    /**
     * The end of the bar as it is actually drawn: the piece's clipped end, or a minute
     * past its start for a piece too short to see. The packer frees a track at this same
     * value, or a widened bar would be drawn over the card that took the slot after it.
     *
     * @param array{start: int, end: int} $segment
     */
    private static function barEnd(array $segment): int
    {
        return max($segment['end'], $segment['start'] + self::MIN_DRAWN_SPAN);
    }

    /** A duration in hours, the unit the timeline's --hour-width scale is expressed in. */
    private static function hours(int $seconds): float
    {
        return round($seconds / self::HOUR, 4);
    }
}
