<?php

// Puts a demo event's places and photos into its kissj fixtures, from the committed overlay
// tests/fixtures/kissj/<slug>/demo.json (Tools\Fixtures\DemoMedia), and draws the photos
// that are missing under www/events/<slug>/demo/ (Tools\Fixtures\DemoPhoto, needs GD with WebP):
//
//   docker run --rm -v "$PWD":/app -w /app php:8.5-alpine sh -c 'apk add --no-cache libwebp-dev libpng-dev libjpeg-turbo-dev >/dev/null && docker-php-ext-configure gd --with-webp --with-jpeg >/dev/null && docker-php-ext-install gd >/dev/null && php bin/demo-media.php <slug>'
//
// The converters write contract 3.0, so the order is: converter, then this, then
// bin/kissj-fixtures.php <slug>. programme-list.json and participant.json are rewritten in
// place; an existing photo is never redrawn (files under www/events/ are cached as
// immutable, so a changed photo ships under a new name). Files written as root need a chown.

declare(strict_types=1);

use Tools\Fixtures\DemoMedia;
use Tools\Fixtures\DemoPhoto;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$slug = $argv[1] ?? throw new InvalidArgumentException('usage: bin/demo-media.php <slug>');
if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
    throw new InvalidArgumentException(sprintf('Not an event slug: %s', $slug));
}
$source = $root . '/tests/fixtures/kissj/' . $slug;
$photos = $root . '/www/events/' . $slug . '/demo';

$read = static function (string $file) use ($source): array {
    $body = file_get_contents($source . '/' . $file);
    if ($body === false) {
        throw new RuntimeException(sprintf('Fixture source missing: %s/%s', $source, $file));
    }
    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException(sprintf('Not a JSON object: %s/%s', $source, $file));
    }

    return $data;
};
$write = static function (string $file, array $data) use ($source): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($source . '/' . $file, $json . "\n") === false) {
        throw new RuntimeException(sprintf('Cannot write %s/%s', $source, $file));
    }
};

$demo = $read('demo.json');
$list = DemoMedia::apply($read('programme-list.json'), $demo, $slug);
$write('programme-list.json', $list);
$write('participant.json', DemoMedia::applyToParticipant($read('participant.json'), $list));

$drawn = 0;
$names = DemoMedia::imageNames($demo);
foreach ($names as $name) {
    if (!is_file($photos . '/' . $name . '.webp')) {
        DemoPhoto::write($name, $photos);
        $drawn++;
    }
}

printf(
    "%d places (%d with GPS), %d programmes with photos, %d photo files (%d drawn) → %s\n",
    count($list['places']),
    count(array_filter($list['places'], static fn(array $place): bool => $place['lat'] !== null)),
    count(array_filter($list['programmes'], static fn(array $programme): bool => $programme['images'] !== [])),
    count($names),
    $drawn,
    $source,
);
