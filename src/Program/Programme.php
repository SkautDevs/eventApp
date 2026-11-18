<?php

declare(strict_types=1);

namespace App\Program;

use App\Time\Zone;

/**
 * One programme as the providers hand it to the screens. Its row form (fromArray(),
 * toArray()) is the JSON stored in programs.json and in the cache's entries — kissj's
 * programme mapped by KissjProgramProvider — and stays exactly as it was when programmes
 * were arrays: `{id, name, section: {id}, start: {date}, end: {date}, lector, location,
 * perex, tools, preregistered}`, a date being a Prague wall-clock time `Y-m-d H:i:s`, or ''
 * for none.
 *
 * Reading is lenient, as the screens were: a missing or irregular field takes the value
 * they used to fall back to (no name '', no section 0, no text null, not preregistered;
 * only an integer `section.id` names a section, so a string one such as "1" is 0 — kissj
 * sends integers),
 * and a date that is empty, missing or does not parse is null. ScheduleBuilder keeps a
 * programme off the screen unless both its dates are set. Only a row without an integer id
 * is no programme at all.
 */
final readonly class Programme
{
    /**
     * @param int $sectionId 0 when the row names none
     * @param \DateTimeImmutable|null $start Prague wall clock; null when the row's value is empty, missing or unparsable
     * @param string|null $location as given: not trimmed
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $sectionId,
        public ?\DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
        public ?string $lector,
        public ?string $location,
        public ?string $perex,
        public ?string $tools,
        public bool $preregistered,
    ) {}

    /**
     * One row of programs.json or of a cache entry. Null when `id` is not an integer.
     * A date string is a Prague wall-clock time; one that carries an offset is converted to Prague.
     *
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $id = $row['id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $section = $row['section'] ?? null;
        $sectionId = is_array($section) ? ($section['id'] ?? null) : null;

        return new self(
            id: $id,
            name: self::text($row['name'] ?? null) ?? '',
            sectionId: is_int($sectionId) ? $sectionId : 0,
            start: self::date($row['start'] ?? null),
            end: self::date($row['end'] ?? null),
            lector: self::text($row['lector'] ?? null),
            location: self::text($row['location'] ?? null),
            perex: self::text($row['perex'] ?? null),
            tools: self::text($row['tools'] ?? null),
            preregistered: ($row['preregistered'] ?? false) === true,
        );
    }

    /**
     * The same row: keys id, name, section{id}, start{date}, end{date}, lector, location,
     * perex, tools, preregistered; a null date is ''.
     *
     * @return array{id: int, name: string, section: array{id: int}, start: array{date: string}, end: array{date: string}, lector: ?string, location: ?string, perex: ?string, tools: ?string, preregistered: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'section' => ['id' => $this->sectionId],
            'start' => ['date' => $this->start?->format('Y-m-d H:i:s') ?? ''],
            'end' => ['date' => $this->end?->format('Y-m-d H:i:s') ?? ''],
            'lector' => $this->lector,
            'location' => $this->location,
            'perex' => $this->perex,
            'tools' => $this->tools,
            'preregistered' => $this->preregistered,
        ];
    }

    /** A text field: a string as it is, a number as its digits, anything else none. */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    /** A `{date: …}` value as a Prague time; null for a missing, empty or unparsable one. */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $date = is_array($value) ? ($value['date'] ?? null) : null;
        if (!is_string($date) || trim($date) === '') {
            return null;
        }
        $prague = Zone::prague();
        try {
            // the zone is the fallback for a wall-clock time; a string with an offset of its own is converted
            return (new \DateTimeImmutable($date, $prague))->setTimezone($prague);
        } catch (\Exception) {
            return null;
        }
    }
}
