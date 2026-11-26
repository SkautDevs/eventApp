<?php

declare(strict_types=1);

namespace App\Program;

/**
 * Maps kissj's `places` list (contract 3.1) onto Place objects. Like Sections, it lives
 * apart from KissjProgramProvider because the stub's fixtures/places.json is that same list.
 *
 * Unlike sections, places never fail the list: they are a 3.1 addition, and a malformed
 * one must not take the programme down with it. A record without a positive integer `id`
 * or a non-empty string `name` is skipped, and of two records with one id the first wins.
 * Coordinates count only as a pair of numbers in range (a JSON integer such as `50` is a
 * number too); anything else leaves the place without GPS.
 */
final class Places
{
    /**
     * @param mixed $raw the `places` value as decoded, trusted for nothing
     * @return array<int, Place> keyed by id, in the order given; empty for anything but a list
     */
    public static function fromKissj(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $places = [];
        foreach ($raw as $record) {
            if (!is_array($record)) {
                continue;
            }
            $id = $record['id'] ?? null;
            $name = $record['name'] ?? null;
            if (!is_int($id) || $id < 1 || !is_string($name) || $name === '' || isset($places[$id])) {
                continue;
            }
            [$lat, $lng] = self::coordinates($record['lat'] ?? null, $record['lng'] ?? null);
            $places[$id] = new Place($id, $name, $lat, $lng);
        }

        return $places;
    }

    /** @return array{float, float}|array{null, null} */
    private static function coordinates(mixed $lat, mixed $lng): array
    {
        if (!is_int($lat) && !is_float($lat) || !is_int($lng) && !is_float($lng)) {
            return [null, null];
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return [null, null];
        }

        return [(float) $lat, (float) $lng];
    }
}
