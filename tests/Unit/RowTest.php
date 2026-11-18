<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\Row;
use PHPUnit\Framework\TestCase;

/** A column reads as the `(string)` and `(int)` casts read it before the rows were typed. */
final class RowTest extends TestCase
{
    public function testStringsAndIntsConvertAsTheCastsDid(): void
    {
        $row = ['text' => 'https://push.example/a', 'number' => 5, 'numeric' => '7', 'none' => null, 'real' => 1.5];

        self::assertSame('https://push.example/a', Row::string($row, 'text'));
        self::assertSame('5', Row::string($row, 'number'));
        self::assertSame('1.5', Row::string($row, 'real'));
        self::assertSame('', Row::string($row, 'none'));
        self::assertSame(5, Row::int($row, 'number'));
        self::assertSame(7, Row::int($row, 'numeric'));
        self::assertSame(0, Row::int($row, 'none'));
        self::assertSame(0, Row::toInt(false), 'fetchColumn() on no row');
    }

    public function testNullStaysNullInANullableColumn(): void
    {
        $row = ['none' => null, 'number' => '3', 'text' => 'Lung'];

        self::assertNull(Row::nullableInt($row, 'none'));
        self::assertSame(3, Row::nullableInt($row, 'number'));
        self::assertNull(Row::nullableString($row, 'none'));
        self::assertSame('Lung', Row::nullableString($row, 'text'));
    }

    public function testAllKeepsTheRowsInOrder(): void
    {
        self::assertSame([['id' => 2], ['id' => 1]], Row::all([['id' => 2], ['id' => 1]]));
    }
}
