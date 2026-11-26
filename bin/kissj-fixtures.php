<?php

// Regenerates events/<slug>/fixtures/ from the kissj responses in tests/fixtures/kissj/<slug>/,
// so a dev event serves that realistic data through the stub provider (PROGRAM_PROVIDER_<SLUG>=local).
//
//   docker run --rm -v "$PWD":/app -w /app php:8.5-alpine php bin/kissj-fixtures.php <slug> [--clamp-all-day=HH:MM-HH:MM]
//
// Everything goes through the real KissjProgramProvider, fed the responses by a Guzzle
// MockHandler, so the stub serves exactly what the kissj provider would. sections.json and
// places.json are kissj's own shape already and are copied as they are (places.json is `[]`
// for a list without `places`). programs.json carries `preregistered` only where it is true
// (the stub reads a missing key as false), `placeId` only where it is set and `images` only
// where there are any; a photo URL loses the converter's fake origin `https://kissj.example`,
// so it is root-relative and served from www/ (bin/demo-media.php draws the photos, and runs
// before this script, after a converter). registration.json is the
// list's registration window, written when either end is set and removed when
// it has none (the stub reads a missing file as no window). registered.json gives the
// fixture participant, read through the session endpoint from participant.json, the
// programmes kissj lists for them, keyed by their participant id. participants.json
// (nicknames) is hand-written and not touched.
// --clamp-all-day moves calendar-export "all day" entries (00:00-23:59) to the given hours
// in the served fixtures only; the kissj corpus under tests/ stays verbatim.

declare(strict_types=1);

use App\Program\KissjProgramProvider;
use App\Program\Programme;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$slug = $argv[1] ?? throw new InvalidArgumentException('usage: bin/kissj-fixtures.php <slug> [--clamp-all-day=HH:MM-HH:MM]');
$source = $root . '/tests/fixtures/kissj/' . $slug;
$target = $root . '/events/' . $slug . '/fixtures';

$read = static function (string $file) use ($source): string {
    $body = file_get_contents($source . '/' . $file);
    if ($body === false) {
        throw new RuntimeException(sprintf('Fixture source missing: %s/%s', $source, $file));
    }

    return $body;
};

$list = $read('programme-list.json');
$participantBody = $read('participant.json');

// The queue is exactly the two calls below, in this order: the list (programmes, sections
// and the window come from one response), then the session read.
$provider = new KissjProgramProvider(
    http: new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $list),
            new Response(200, ['Content-Type' => 'application/json'], $participantBody),
        ])),
        'base_uri' => 'https://kissj.example/',
    ]),
    apiKey: 'unused',
    origin: 'https://kissj.example',
);

// the rows programs.json stores, as the stub reads them back
$programs = array_map(static fn(Programme $programme): array => $programme->toArray(), $provider->getPrograms());
foreach (array_slice($argv, 2) as $arg) {
    $hour = '(?:[01]\d|2[0-3]):[0-5]\d';
    if (preg_match('/^--clamp-all-day=(' . $hour . ')-(' . $hour . ')$/', $arg, $m) !== 1) {
        throw new InvalidArgumentException(sprintf('Unknown argument "%s"; expected --clamp-all-day=HH:MM-HH:MM', $arg));
    }
    $programs = \Tools\Fixtures\AllDayClamp::apply($programs, $m[1], $m[2]);
}
$window = $provider->getRegistrationWindow();
// the session endpoint is what the provider reads a participant through; the token is a dummy
$participant = $provider->getParticipant('fixtures');
$registered = array_map(static fn(Programme $programme): int => $programme->id, $participant->programmes);
// the stub reads a missing key as false, so only the true ones are spelled out; a null
// placeId and an empty images list are left out by Programme::toArray() already
$programs = array_map(static function (array $program): array {
    if ($program['preregistered'] !== true) {
        unset($program['preregistered']);
    }
    if (isset($program['images'])) {
        $program['images'] = array_map(static function (array $image): array {
            foreach (['url', 'thumbUrl'] as $key) {
                if (str_starts_with($image[$key], 'https://kissj.example/')) {
                    $image[$key] = substr($image[$key], strlen('https://kissj.example'));
                }
            }

            return $image;
        }, $program['images']);
    }

    return $program;
}, $programs);
$decoded = json_decode($list, true, flags: JSON_THROW_ON_ERROR);
$sections = $decoded['sections'];
$places = $decoded['places'] ?? [];

$write = static function (string $file, array $data) use ($target): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($target . '/' . $file, $json . "\n") === false) {
        throw new RuntimeException(sprintf('Cannot write %s/%s', $target, $file));
    }
};

if (!is_dir($target) && !mkdir($target, 0777, true)) {
    throw new RuntimeException(sprintf('Cannot create %s', $target));
}
$write('programs.json', $programs);
$write('sections.json', $sections);
$write('places.json', $places);
$write('registered.json', [(string) $participant->id => $registered]);
if ($window->start !== null || $window->end !== null) {
    $write('registration.json', ['start' => $window->start?->format(DATE_ATOM), 'end' => $window->end?->format(DATE_ATOM)]);
} elseif (is_file($target . '/registration.json') && !unlink($target . '/registration.json')) {
    throw new RuntimeException(sprintf('Cannot remove %s/registration.json', $target));
}

printf(
    "%d programmes, %d sections, %d places (places.json), %d registered for participant %d, registration %s → %s\n",
    count($programs),
    count($sections),
    count($places),
    count($registered),
    $participant->id,
    $window->start === null && $window->end === null ? 'not set' : 'set',
    $target,
);
