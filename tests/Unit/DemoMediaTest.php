<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tools\Fixtures\DemoMedia;

/**
 * The pure half of bin/demo-media.php: the committed overlay (demo.json) turns a kissj
 * 3.0 programme list into contract 3.1, with places, `placeId` and photos on the
 * converter's fake origin.
 */
final class DemoMediaTest extends TestCase
{
    private static function programme(int $id, string $place): array
    {
        return [
            'id' => $id,
            'name' => 'P' . $id,
            'sectionId' => 1,
            'description' => '',
            'place' => $place,
            'start' => '2027-05-19T12:00:00+02:00',
            'end' => '2027-05-19T13:00:00+02:00',
            'isAutoenrolled' => false,
            'targetRoles' => ['ist'],
        ];
    }

    private static function list(): array
    {
        return [
            'enrolment' => ['start' => null, 'end' => null],
            'sections' => [['id' => 1, 'name' => 'S', 'subtitle' => null]],
            'programmes' => [
                self::programme(1, 'B'),
                self::programme(2, 'A'),
                self::programme(3, ''),
                self::programme(4, 'B'),
            ],
        ];
    }

    public function testPlacesComeFromThePlaceStringsSortedByName(): void
    {
        $out = DemoMedia::apply(self::list(), ['places' => ['A' => ['lat' => 50.1, 'lng' => 15.2]], 'images' => []], 'x');

        self::assertSame(['enrolment', 'sections', 'places', 'programmes'], array_keys($out));
        self::assertSame([
            ['id' => 1, 'name' => 'A', 'lat' => 50.1, 'lng' => 15.2],
            ['id' => 2, 'name' => 'B', 'lat' => null, 'lng' => null],
        ], $out['places']);
        self::assertSame([2, 1, null, 2], array_column($out['programmes'], 'placeId'));
        self::assertSame(
            ['id', 'name', 'sectionId', 'description', 'place', 'placeId', 'start', 'end', 'isAutoenrolled', 'targetRoles', 'images'],
            array_keys($out['programmes'][0]),
        );
        self::assertSame([], $out['programmes'][0]['images']);
    }

    public function testImagesUseTheConvertersOriginAndTheThumbSuffix(): void
    {
        $out = DemoMedia::apply(self::list(), ['places' => [], 'images' => ['1' => ['a', 'b']]], 'x');

        self::assertCount(2, $out['programmes'][0]['images']);
        self::assertSame(
            ['url' => 'https://kissj.example/events/x/demo/b.webp', 'thumbUrl' => 'https://kissj.example/events/x/demo/b-thumb.webp', 'alt' => ''],
            $out['programmes'][0]['images'][1],
        );
        self::assertSame([], $out['programmes'][1]['images']);
    }

    public function testATypoInTheOverlayThrows(): void
    {
        try {
            DemoMedia::apply(self::list(), ['places' => ['Nikde' => null], 'images' => []], 'x');
            self::fail('an unknown place name must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Nikde', $e->getMessage());
        }

        try {
            DemoMedia::apply(self::list(), ['places' => [], 'images' => ['999' => ['a']]], 'x');
            self::fail('an unknown programme id must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('999', $e->getMessage());
        }
    }

    public function testApplyIsIdempotent(): void
    {
        $demo = ['places' => ['A' => ['lat' => 50.1, 'lng' => 15.2]], 'images' => ['1' => ['a', 'b'], '2' => ['a']]];
        $once = DemoMedia::apply(self::list(), $demo, 'x');

        self::assertSame($once, DemoMedia::apply($once, $demo, 'x'));
    }

    public function testTheParticipantGetsTheProgrammesFieldsButNoPlaces(): void
    {
        $applied = DemoMedia::apply(self::list(), ['places' => [], 'images' => ['1' => ['a']]], 'x');
        $participant = ['participant' => ['id' => 1, 'nickname' => 'N'], 'programmes' => [self::programme(1, 'B')]];

        $out = DemoMedia::applyToParticipant($participant, $applied);

        self::assertFalse(array_key_exists('places', $out));
        self::assertSame($applied['programmes'][0], $out['programmes'][0]);
        self::assertSame($out, DemoMedia::applyToParticipant($out, $applied));
    }

    /** miquik26's rooms are numbers: "448" sorts after "1000" by strcmp, and stays a string */
    public function testANumericPlaceNameStaysAString(): void
    {
        $list = self::list();
        $list['programmes'] = [self::programme(1, '448'), self::programme(2, '1000')];

        self::assertSame(['1000', '448'], array_column(DemoMedia::apply($list, [], 'x')['places'], 'name'));
    }

    public function testImageNamesAreUniqueInOrder(): void
    {
        self::assertSame(['a', 'b', 'c'], DemoMedia::imageNames(['images' => ['1' => ['a', 'b'], '4' => ['b', 'c', 'a']]]));
    }
}
