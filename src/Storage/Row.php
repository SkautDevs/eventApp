<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The columns of a row PDO fetched, read as the type the schema gives them. SQLite hands
 * back an int, a float, a string or null for every column; each reader converts those as
 * PHP's own conversion does, and anything else — which the driver never returns — reads
 * as empty. Pure.
 */
final class Row
{
    /**
     * fetchAll(PDO::FETCH_ASSOC)'s result as rows.
     *
     * @param array<mixed> $fetched
     * @return list<array<mixed>>
     */
    public static function all(array $fetched): array
    {
        return array_values(array_filter($fetched, is_array(...)));
    }

    /** @param array<mixed> $row */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<mixed> $row */
    public static function nullableString(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::string($row, $column);
    }

    /** @param array<mixed> $row */
    public static function int(array $row, string $column): int
    {
        return self::toInt($row[$column] ?? null);
    }

    /** @param array<mixed> $row */
    public static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /** One value of a column, as fetchAll(PDO::FETCH_COLUMN) returns them. */
    public static function toInt(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }
}
