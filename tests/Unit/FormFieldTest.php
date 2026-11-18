<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\FormField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A form field reads exactly as `(string) ($body[$name] ?? '')` and `(int) ($body[$name] ?? $default)`
 * read it before the admin forms were typed, an array included, minus the conversion's warning.
 */
final class FormFieldTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function texts(): iterable
    {
        yield 'a string' => [' Lung ', ' Lung '];
        yield 'null' => [null, ''];
        yield 'an int' => [42, '42'];
        yield 'a float' => [1.5, '1.5'];
        yield 'true' => [true, '1'];
        yield 'false' => [false, ''];
        yield 'an array' => [['1'], 'Array'];
        yield 'an empty array' => [[], 'Array'];
        yield 'an XML node' => [new \SimpleXMLElement('<title>Změna</title>'), 'Změna'];
    }

    #[DataProvider('texts')]
    public function testTextReadsAsTheStringConversion(mixed $value, string $expected): void
    {
        self::assertSame($expected, FormField::text(['field' => $value], 'field'));
    }

    public function testAMissingFieldIsEmptyText(): void
    {
        self::assertSame('', FormField::text([], 'field'));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function ints(): iterable
    {
        yield 'an int' => [3, 3];
        yield 'a numeric string' => ['3', 3];
        yield 'a leading number' => ['3x', 3];
        yield 'no number' => ['abc', 0];
        yield 'a float' => [2.9, 2];
        yield 'true' => [true, 1];
        yield 'null' => [null, 7];
        yield 'an array' => [['3'], 7];
        yield 'an XML node' => [new \SimpleXMLElement('<page>4</page>'), 4];
    }

    #[DataProvider('ints')]
    public function testIntReadsAsTheIntegerConversion(mixed $value, int $expected): void
    {
        self::assertSame($expected, FormField::int(['field' => $value], 'field', 7));
    }

    public function testAMissingFieldIsTheDefault(): void
    {
        self::assertSame(7, FormField::int([], 'field', 7));
    }
}
