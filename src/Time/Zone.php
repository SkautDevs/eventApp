<?php

declare(strict_types=1);

namespace App\Time;

/**
 * The app's one time zone. Every date the app formats or compares is a Prague one: the
 * provider's naive datetimes are wall-clock times there, Domů's phases and days, the
 * notifications' day groups and "včera", the timeline's "today" and the freshness stamp
 * are all counted on its calendar, whatever zone PHP or the instant itself is in.
 */
final class Zone
{
    public const string PRAGUE = 'Europe/Prague';

    /** The zone as an object, for setTimezone() and the DateTime constructors. */
    public static function prague(): \DateTimeZone
    {
        return new \DateTimeZone(self::PRAGUE);
    }

    /** Midnight of the Prague day that holds `$now`, whatever zone `$now` carries. */
    public static function midnight(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone(self::prague())->setTime(0, 0);
    }
}
