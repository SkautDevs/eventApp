<?php

declare(strict_types=1);

namespace App\Http;

/**
 * One field of a parsed request body or query, read as the admin forms read it before
 * their values were typed: exactly what PHP's own string and integer conversion makes of
 * it. A parsed body holds strings, arrays and, from a JSON body, other scalars; an XML
 * body (Slim parses one into a SimpleXMLElement) holds Stringable nodes. Nothing here
 * throws, so a malformed field leads to the same 422 or default it always did. Pure.
 */
final class FormField
{
    /**
     * The field as text. A missing or null field is '', a scalar or a Stringable node its
     * string form; an array is 'Array', as the string conversion has always made it — an
     * invalid value stays invalid (a target no programme has) instead of becoming the empty
     * string, which for the send form's target means everyone.
     *
     * @param array<mixed> $fields
     */
    public static function text(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value), $value instanceof \Stringable => (string) $value,
            is_array($value) => 'Array',
            default => '',
        };
    }

    /**
     * The field as an integer: a scalar or a Stringable node as PHP's integer conversion
     * reads it ('3x' is 3, 'abc' is 0), a missing or null field, or an array, $default.
     *
     * @param array<mixed> $fields
     */
    public static function int(array $fields, string $name, int $default): int
    {
        $value = $fields[$name] ?? null;

        return match (true) {
            is_int($value) => $value,
            is_string($value), is_float($value), is_bool($value) => (int) $value,
            $value instanceof \Stringable => (int) (string) $value,
            default => $default,
        };
    }
}
