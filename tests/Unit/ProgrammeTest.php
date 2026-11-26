<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Program\Programme;
use App\Program\ProgrammeImage;
use PHPUnit\Framework\TestCase;

final class ProgrammeTest extends TestCase
{
    public function testARowRoundTrips(): void
    {
        $row = ['id' => 7, 'name' => 'Lanovka', 'section' => ['id' => 2], 'start' => ['date' => '2027-05-21 09:00:00'], 'end' => ['date' => '2027-05-21 12:30:00'],
            'lector' => null, 'location' => ' Žlutý les ', 'perex' => "řádek\nřádek", 'tools' => null, 'preregistered' => false];
        $programme = Programme::fromArray($row);
        self::assertNotNull($programme);
        self::assertNotNull($programme->start);
        self::assertSame('2027-05-21T09:00:00+02:00', $programme->start->format(DATE_ATOM));
        self::assertSame(' Žlutý les ', $programme->location);
        self::assertSame($row, $programme->toArray());
    }

    /** The three irregular rows of tests/fixtures/events/programs, which the screens must keep handling as today. */
    public function testAnEmptyStartAndANullEndAreNullDates(): void
    {
        $programme = Programme::fromArray(['id' => 3, 'name' => 'Bez času', 'section' => ['id' => 1], 'start' => ['date' => ''], 'end' => null]);
        self::assertNotNull($programme);
        self::assertNull($programme->start);
        self::assertNull($programme->end);
        self::assertSame(['date' => ''], $programme->toArray()['start']);
    }

    public function testAnUnparsableDateIsANullDate(): void
    {
        self::assertNull(Programme::fromArray(['id' => 4, 'name' => 'x', 'start' => ['date' => 'není datum']])?->start);
    }

    public function testARowWithoutAnIntegerIdIsNoProgramme(): void
    {
        self::assertNull(Programme::fromArray(['id' => '7', 'name' => 'x']));
        self::assertNull(Programme::fromArray(['name' => 'x']));
    }

    public function testMissingKeysTakeTheDefaultsTheScreensUsedToApply(): void
    {
        $programme = Programme::fromArray(['id' => 5]);
        self::assertNotNull($programme);
        self::assertSame(['', 0, null, null, false], [$programme->name, $programme->sectionId, $programme->location, $programme->perex, $programme->preregistered]);
    }

    /** A cache file written by the release before this one. */
    public function testAnEntryOfThePreviousReleaseIsRead(): void
    {
        $stored = json_decode('{"id":1,"name":"Volejbal","section":{"id":1},"start":{"date":"2026-09-16 08:00:00"},"end":{"date":"2026-09-16 10:00:00"},"lector":null,"location":"Volejbalové hřiště","perex":null,"tools":null,"preregistered":false}', true);
        self::assertSame($stored, Programme::fromArray($stored)?->toArray());
    }

    public function testARowWithoutPlaceOrImagesHasNone(): void
    {
        $programme = Programme::fromArray(['id' => 1, 'name' => 'X']);
        self::assertNotNull($programme);
        self::assertNull($programme->placeId);
        self::assertSame([], $programme->images);
        self::assertArrayNotHasKey('placeId', $programme->toArray());
        self::assertArrayNotHasKey('images', $programme->toArray());
    }

    public function testPlaceIdAndImagesRoundTrip(): void
    {
        $images = [['url' => '/events/x/demo/a.webp', 'thumbUrl' => '/events/x/demo/a-thumb.webp', 'alt' => '']];
        $programme = Programme::fromArray(['id' => 1, 'name' => 'X', 'placeId' => 7, 'images' => $images]);
        self::assertNotNull($programme);
        self::assertSame(7, $programme->placeId);
        self::assertEquals([new ProgrammeImage('/events/x/demo/a.webp', '/events/x/demo/a-thumb.webp', '')], $programme->images);
        $row = $programme->toArray();
        self::assertSame(7, $row['placeId'] ?? null);
        self::assertSame($images, $row['images'] ?? null);
        self::assertEquals($programme, Programme::fromArray($row));
    }

    public function testIrregularPlaceAndImagesAreDropped(): void
    {
        foreach (['7', 0, -1, 7.0, null, true] as $placeId) {
            self::assertNull(Programme::fromArray(['id' => 1, 'placeId' => $placeId])?->placeId, var_export($placeId, true));
        }
        self::assertSame([], Programme::fromArray(['id' => 1, 'images' => 'x'])?->images);
        self::assertSame([], Programme::fromArray(['id' => 1, 'images' => ['a' => ['url' => 'u', 'thumbUrl' => 't']]])?->images, 'not a list');

        $images = Programme::fromArray(['id' => 1, 'images' => [
            ['url' => '', 'thumbUrl' => 't'],
            ['url' => 'u'],
            'x',
            ['url' => 'u', 'thumbUrl' => 't', 'alt' => 5],
        ]])?->images;

        self::assertEquals([new ProgrammeImage('u', 't', '')], $images);
    }
}
