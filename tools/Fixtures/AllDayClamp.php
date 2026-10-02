<?php

declare(strict_types=1);

namespace Tools\Fixtures;

/**
 * Dev-event only: a calendar export's "all day" (00:00–23:59) is kept verbatim in the kissj
 * corpus the provider tests pin, but it stretches a day's timeline to midnight-to-midnight.
 * For the served dev fixtures it is moved to the camp's waking hours.
 */
final class AllDayClamp
{
    public static function apply(array $programmes, string $from, string $to): array
    {
        return array_map(static function (array $p) use ($from, $to): array {
            if (substr($p['start']['date'], 11) === '00:00:00' && substr($p['end']['date'], 11) === '23:59:00') {
                $p['start']['date'] = substr($p['start']['date'], 0, 10) . ' ' . $from . ':00';
                $p['end']['date'] = substr($p['end']['date'], 0, 10) . ' ' . $to . ':00';
            }

            return $p;
        }, $programmes);
    }
}
