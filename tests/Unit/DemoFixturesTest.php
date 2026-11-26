<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Program\KissjProgramProvider;
use App\Program\Programme;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tools\Fixtures\AllDayClamp;

/**
 * The committed demo events (bin/demo-media.php, then bin/kissj-fixtures.php): the kissj
 * fixtures are contract 3.1, every photo the served fixtures name is on disk within the
 * size budget, and the served places and times are the provider's own mapping.
 */
final class DemoFixturesTest extends TestCase
{
    /** bin/kissj-fixtures.php korbo26 --clamp-all-day=08:00-22:00 */
    private const array CLAMP = ['korbo26' => ['08:00', '22:00']];

    /** @return iterable<string, array{string}> */
    public static function events(): iterable
    {
        foreach (['obrok27', 'navigamus27', 'korbo26', 'miquik26'] as $slug) {
            yield $slug => [$slug];
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<mixed> */
    private static function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(self::root() . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private static function served(string $slug): array
    {
        return self::json('events/' . $slug . '/fixtures/programs.json');
    }

    #[DataProvider('events')]
    public function testTheKissjFixturesAreContractThreeOne(string $slug): void
    {
        $list = self::json('tests/fixtures/kissj/' . $slug . '/programme-list.json');
        $keys = array_keys($list);
        $sections = array_search('sections', $keys, true);
        self::assertIsInt($sections);
        self::assertSame('places', $keys[$sections + 1] ?? null, 'places right after sections');
        foreach ($list['programmes'] as $programme) {
            self::assertArrayHasKey('placeId', $programme, 'programme ' . $programme['id']);
            self::assertArrayHasKey('images', $programme, 'programme ' . $programme['id']);
        }

        $participant = self::json('tests/fixtures/kissj/' . $slug . '/participant.json');
        self::assertArrayNotHasKey('places', $participant);
        foreach ($participant['programmes'] as $programme) {
            self::assertArrayHasKey('placeId', $programme, 'participant programme ' . $programme['id']);
            self::assertArrayHasKey('images', $programme, 'participant programme ' . $programme['id']);
        }
    }

    #[DataProvider('events')]
    public function testEveryPhotoExistsAndFitsTheBudget(string $slug): void
    {
        $checked = 0;
        foreach (self::served($slug) as $programme) {
            foreach ($programme['images'] ?? [] as $image) {
                $sizes = [];
                foreach ([$image['url'], $image['thumbUrl']] as $url) {
                    self::assertStringStartsWith('/events/' . $slug . '/demo/', $url);
                    self::assertStringEndsWith('.webp', $url);
                    $file = self::root() . '/www' . $url;
                    self::assertFileExists($file);
                    $sizes[] = (int) filesize($file);
                }
                self::assertLessThanOrEqual(61440, array_sum($sizes), $image['url'] . ' with its thumb');
                $checked++;
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    #[DataProvider('events')]
    public function testListsShowMixedRows(string $slug): void
    {
        $counts = array_map(static fn(array $programme): int => count($programme['images'] ?? []), self::served($slug));
        $with = count(array_filter($counts, static fn(int $count): bool => $count > 0));

        self::assertNotEmpty(array_filter($counts, static fn(int $count): bool => $count >= 2), 'a row with a gallery');
        self::assertNotEmpty(array_filter($counts, static fn(int $count): bool => $count === 1), 'a row with one photo');
        self::assertGreaterThan($with, count($counts) - $with, 'most rows have no photo');
    }

    #[DataProvider('events')]
    public function testPlacesJsonMatchesThePlaceStrings(string $slug): void
    {
        $places = self::json('events/' . $slug . '/fixtures/places.json');
        $programmes = self::served($slug);
        $locations = array_values(array_unique(array_filter(
            array_column($programmes, 'location'),
            static fn(mixed $location): bool => is_string($location) && $location !== '',
        )));
        sort($locations, SORT_STRING);

        self::assertSame($locations, array_column($places, 'name'));
        $ids = array_column($places, 'id', 'name');
        foreach ($programmes as $programme) {
            if (is_string($programme['location']) && $programme['location'] !== '') {
                self::assertSame($ids[$programme['location']], $programme['placeId'] ?? null, 'programme ' . $programme['id']);
            } else {
                self::assertArrayNotHasKey('placeId', $programme, 'programme ' . $programme['id']);
            }
        }

        $withGps = array_column(array_filter($places, static fn(array $place): bool => $place['lat'] !== null && $place['lng'] !== null), 'name');
        $expected = match ($slug) {
            'korbo26', 'miquik26' => [],
            'obrok27' => array_values(array_diff($locations, ['Celý tábor'])),
            'navigamus27' => $locations,
        };
        self::assertSame($expected, array_values($withGps));
    }

    /** @return iterable<string, array{string}> */
    public static function eventsWithAPlan(): iterable
    {
        foreach (['obrok27', 'navigamus27'] as $slug) {
            yield $slug => [$slug];
        }
    }

    /** Every demo GPS point lies on the event's plan, so every pin of these events opens Plán. */
    #[DataProvider('eventsWithAPlan')]
    public function testEveryPlaceWithGpsLiesOnThePlan(string $slug): void
    {
        $bounds = EventConfig::load(self::root() . '/events', $slug)->map?->planBounds;
        self::assertNotNull($bounds, $slug . ': map.planBounds');

        $checked = 0;
        foreach (self::json('events/' . $slug . '/fixtures/places.json') as $place) {
            self::assertIsArray($place);
            if (!is_float($place['lat'] ?? null) || !is_float($place['lng'] ?? null)) {
                continue;
            }
            self::assertTrue($bounds->contains($place['lat'], $place['lng']), $slug . ': ' . (is_string($place['name'] ?? null) ? $place['name'] : '?'));
            $checked++;
        }
        self::assertGreaterThan(0, $checked, $slug . ': places with GPS');
    }

    #[DataProvider('events')]
    public function testTheServedTimesAreTheProvidersMapping(string $slug): void
    {
        $body = (string) file_get_contents(self::root() . '/tests/fixtures/kissj/' . $slug . '/programme-list.json');
        $provider = new KissjProgramProvider(
            http: new Client([
                'handler' => HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)])),
                'base_uri' => 'https://kissj.example/',
            ]),
            apiKey: 'unused',
            origin: 'https://kissj.example',
        );
        $mapped = array_map(static fn(Programme $programme): array => $programme->toArray(), $provider->getPrograms());
        if (isset(self::CLAMP[$slug])) {
            $mapped = AllDayClamp::apply($mapped, ...self::CLAMP[$slug]);
        }

        $times = static fn(array $rows): array => array_map(static fn(array $row): array => [$row['id'], $row['start'], $row['end']], $rows);
        self::assertSame($times($mapped), $times(self::served($slug)));
    }
}
