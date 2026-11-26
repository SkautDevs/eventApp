<?php

declare(strict_types=1);

namespace Tools\Fixtures;

/**
 * Puts a demo event's places and photos into its kissj-shaped fixtures (contract 3.1), from
 * the committed overlay tests/fixtures/kissj/<slug>/demo.json:
 *
 *     {"places": {"<place string>": {"lat": …, "lng": …} | null}, "images": {"<programme id>": ["<name>", …]}}
 *
 * The converters write contract 3.0, so bin/demo-media.php runs this after them. Every
 * distinct non-empty `place` string becomes a place; ids run from 1 in strcmp order of the
 * names. Photos sit on the converter's fake origin, which bin/kissj-fixtures.php strips
 * again for the served fixtures. Applying twice gives the same list.
 */
final class DemoMedia
{
    public const string ORIGIN = 'https://kissj.example';

    /**
     * @param array<string, mixed> $list the programme-list.json body
     * @param array<string, mixed> $demo the demo.json body
     * @return array<string, mixed> the list with `places` right after `sections`, and
     *                              `placeId` (after `place`) and `images` (after `targetRoles`) on every programme
     */
    public static function apply(array $list, array $demo, string $slug): array
    {
        $programmes = $list['programmes'];
        $names = [];
        foreach ($programmes as $programme) {
            $place = self::place($programme);
            if ($place !== '') {
                $names[$place] = true;
            }
        }
        // a numeric name such as "401" is an integer key: back to strings before sorting
        $names = array_map(strval(...), array_keys($names));
        usort($names, strcmp(...));
        $ids = array_flip($names);

        $gps = $demo['places'] ?? [];
        foreach (array_keys($gps) as $name) {
            if (!isset($ids[$name])) {
                throw new \InvalidArgumentException(sprintf('demo.json names a place no programme has: "%s"', $name));
            }
        }
        $images = self::images($demo, array_column($programmes, 'id'));

        $places = [];
        foreach ($names as $index => $name) {
            $point = $gps[$name] ?? null;
            $places[] = [
                'id' => $index + 1,
                'name' => $name,
                'lat' => $point === null ? null : (float) $point['lat'],
                'lng' => $point === null ? null : (float) $point['lng'],
            ];
        }

        $out = [];
        foreach ($list as $key => $value) {
            if ($key === 'places') {
                continue;
            }
            if ($key === 'programmes') {
                $value = array_map(static function (array $programme) use ($ids, $images, $slug): array {
                    $place = self::place($programme);
                    $photos = array_map(
                        static fn(string $name): array => [
                            'url' => self::ORIGIN . '/events/' . $slug . '/demo/' . $name . '.webp',
                            'thumbUrl' => self::ORIGIN . '/events/' . $slug . '/demo/' . $name . '-thumb.webp',
                            'alt' => '',
                        ],
                        $images[$programme['id']] ?? [],
                    );

                    return self::withFields($programme, $place === '' ? null : $ids[$place] + 1, $photos);
                }, $value);
            }
            $out[$key] = $value;
            if ($key === 'sections') {
                $out['places'] = $places;
            }
        }

        return $out;
    }

    /**
     * The participant body's programmes get the `placeId` and `images` their ids have in the
     * applied list; the body itself gets no `places` (kissj sends them with the list only).
     *
     * @param array<string, mixed> $participant the participant.json body
     * @param array<string, mixed> $appliedList apply()'s result
     * @return array<string, mixed>
     */
    public static function applyToParticipant(array $participant, array $appliedList): array
    {
        $byId = array_column($appliedList['programmes'], null, 'id');
        unset($participant['places']);
        $participant['programmes'] = array_map(static function (array $programme) use ($byId): array {
            $listed = $byId[$programme['id']] ?? throw new \InvalidArgumentException(
                sprintf('participant.json has programme %d, which the list does not', $programme['id']),
            );

            return self::withFields($programme, $listed['placeId'], $listed['images']);
        }, $participant['programmes']);

        return $participant;
    }

    /**
     * Every photo name the overlay uses, each once, in the order of first use.
     *
     * @param array<string, mixed> $demo
     * @return list<string>
     */
    public static function imageNames(array $demo): array
    {
        $names = [];
        foreach ($demo['images'] ?? [] as $list) {
            foreach ($list as $name) {
                $names[$name] = true;
            }
        }

        return array_map(strval(...), array_keys($names));
    }

    /**
     * The overlay's photo names by programme id, checked against the list's ids.
     *
     * @param array<string, mixed> $demo
     * @param list<mixed> $programmeIds
     * @return array<int, list<string>>
     */
    private static function images(array $demo, array $programmeIds): array
    {
        $images = [];
        foreach ($demo['images'] ?? [] as $id => $names) {
            if (!in_array((string) $id, array_map(strval(...), $programmeIds), true)) {
                throw new \InvalidArgumentException(sprintf('demo.json has photos for a programme the list does not: %s', $id));
            }
            foreach ($names as $name) {
                if (!is_string($name) || preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
                    throw new \InvalidArgumentException(sprintf('demo.json photo name of programme %s is not [a-z0-9-]+: %s', $id, json_encode($name)));
                }
            }
            $images[(int) $id] = array_values($names);
        }

        return $images;
    }

    /** @param array<string, mixed> $programme */
    private static function place(array $programme): string
    {
        $place = $programme['place'] ?? '';

        return is_string($place) ? $place : '';
    }

    /**
     * The programme with `placeId` right after `place` and `images` right after
     * `targetRoles` (each at the end when its anchor is missing); old values are replaced.
     *
     * @param array<string, mixed> $programme
     * @param list<array{url: string, thumbUrl: string, alt: string}> $images
     * @return array<string, mixed>
     */
    private static function withFields(array $programme, ?int $placeId, array $images): array
    {
        unset($programme['placeId'], $programme['images']);
        $out = [];
        foreach ($programme as $key => $value) {
            $out[$key] = $value;
            if ($key === 'place') {
                $out['placeId'] = $placeId;
            }
            if ($key === 'targetRoles') {
                $out['images'] = $images;
            }
        }
        if (!array_key_exists('placeId', $out)) {
            $out['placeId'] = $placeId;
        }
        if (!array_key_exists('images', $out)) {
            $out['images'] = $images;
        }

        return $out;
    }
}
