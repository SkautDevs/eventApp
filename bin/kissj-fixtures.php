<?php

// Regenerates events/<slug>/fixtures/ from the kissj responses in tests/fixtures/kissj/<slug>/,
// so a dev event serves that realistic data through the stub provider.
//
//   docker run --rm -v "$PWD":/app -w /app php:8.3-alpine php bin/kissj-fixtures.php <slug> [--clamp-all-day=HH:MM-HH:MM]
//
// The programmes go through the real KissjProgramProvider, fed the responses by a Guzzle
// MockHandler, so the stub serves exactly what the kissj provider would. sections.json is
// kissj's own shape already and is copied as it is. registered.json gives the event's TIE
// participant the programmes kissj lists for them. The TIE code is derived from the slug:
// the slug with its trailing digits removed, upper-cased, plus 1 (korbo26 -> KORBO1).
// --clamp-all-day moves calendar-export "all day" entries (00:00-23:59) to the given hours
// in the served fixtures only; the kissj corpus under tests/ stays verbatim.

declare(strict_types=1);

use App\Auth\Identity;
use App\Program\KissjProgramProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$slug = $argv[1] ?? throw new InvalidArgumentException('usage: bin/kissj-fixtures.php <slug> [--clamp-all-day=HH:MM-HH:MM]');
$tieCode = strtoupper(preg_replace('/\d+$/', '', $slug)) . '1';
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
$tie = $read('participant-tie.json');

// The queue is exactly the two calls below, in this order.
$provider = new KissjProgramProvider(
    http: new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $list),
            new Response(200, ['Content-Type' => 'application/json'], $tie),
        ])),
        'base_uri' => 'https://kissj.example/',
    ]),
    apiKey: 'unused',
);

$programs = $provider->getPrograms();
foreach (array_slice($argv, 2) as $arg) {
    $hour = '(?:[01]\d|2[0-3]):[0-5]\d';
    if (preg_match('/^--clamp-all-day=(' . $hour . ')-(' . $hour . ')$/', $arg, $m) !== 1) {
        throw new InvalidArgumentException(sprintf('Unknown argument "%s"; expected --clamp-all-day=HH:MM-HH:MM', $arg));
    }
    $programs = \Tools\Fixtures\AllDayClamp::apply($programs, $m[1], $m[2]);
}
$registered = array_column(
    $provider->getProgramsForIdentity(new Identity(displayName: 'TIE ' . $tieCode, tieCode: $tieCode)),
    'id',
);
$sections = json_decode($list, true, flags: JSON_THROW_ON_ERROR)['sections'];

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
$write('registered.json', ['tie:' . $tieCode => $registered]);

printf("%d programmes, %d sections, %d registered for TIE %s → %s\n", count($programs), count($sections), count($registered), $tieCode, $target);
