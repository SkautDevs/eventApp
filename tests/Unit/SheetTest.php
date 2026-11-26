<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Module\Programs\View\Sheet;
use App\Program\ProgrammeImage;
use PHPUnit\Framework\TestCase;

/**
 * Final review F5: the sheet's gallery frames are keyed by their photo, so a revalidation
 * that reorders or replaces the photos makes the morph insert a fresh frame instead of
 * writing a new `src` into a frame already marked loaded.
 */
final class SheetTest extends TestCase
{
    /** @param list<string> $urls */
    private static function sheet(array $urls): Sheet
    {
        return new Sheet(
            id: 1,
            name: 'Volejbal',
            when: 'po 8:00',
            location: null,
            perex: null,
            lector: null,
            tools: null,
            section: null,
            page: null,
            sectionId: 1,
            registered: false,
            preregistered: false,
            images: array_map(static fn(string $url) => new ProgrammeImage($url, $url . '/thumb', ''), $urls),
        );
    }

    public function testAKeyFollowsItsPhotoNotItsPosition(): void
    {
        $keys = self::sheet(['https://k.example/a', 'https://k.example/b'])->imageKeys;
        self::assertSame([Sheet::imageKey('https://k.example/a'), Sheet::imageKey('https://k.example/b')], $keys);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $keys[0]);
        self::assertNotSame($keys[0], $keys[1]);

        self::assertSame(array_reverse($keys), self::sheet(['https://k.example/b', 'https://k.example/a'])->imageKeys);
        self::assertSame($keys, self::sheet(['https://k.example/a', 'https://k.example/b'])->imageKeys, 'stable across renders');
    }

    public function testTheSamePhotoTwiceGetsTwoKeys(): void
    {
        $keys = self::sheet(['https://k.example/a', 'https://k.example/a', 'https://k.example/a'])->imageKeys;

        self::assertSame(3, count(array_unique($keys)));
        self::assertSame(Sheet::imageKey('https://k.example/a'), $keys[0]);
    }

    public function testCompletedKeepsTheKeys(): void
    {
        $sheet = self::sheet(['https://k.example/a']);

        self::assertSame($sheet->imageKeys, $sheet->completed([], null, '/x')->imageKeys);
    }
}
