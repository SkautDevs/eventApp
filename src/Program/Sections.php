<?php

declare(strict_types=1);

namespace App\Program;

/**
 * Maps kissj's `sections` list onto Section objects — see
 * kissj's API contract 3.0. It lives apart from KissjProgramProvider because the stub's
 * fixtures/sections.json is that same list, so both providers map it identically.
 */
final class Sections
{
    /**
     * @param mixed $raw the `sections` value as decoded, trusted for nothing
     * @return array<int, Section> keyed by id, in the order kissj sent them — which is the display order
     */
    public static function fromKissj(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ProgramDataException('kissj sections are not a list');
        }

        $sections = [];
        foreach ($raw as $section) {
            if (!is_array($section)) {
                throw new ProgramDataException('kissj section record is not an object');
            }
            if (!isset($section['id']) || !is_int($section['id'])) {
                throw new ProgramDataException('kissj section record has no integer id');
            }
            if (!isset($section['name']) || !is_string($section['name'])) {
                throw new ProgramDataException('kissj section record has no name');
            }

            $sections[$section['id']] = new Section($section['id'], $section['name'], self::optionalString($section, 'subtitle'));
        }

        return $sections;
    }

    /** @param array<mixed> $record */
    private static function optionalString(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new ProgramDataException(sprintf('kissj section %s is not a string', $key));
        }

        return $value === '' ? null : $value;
    }
}
