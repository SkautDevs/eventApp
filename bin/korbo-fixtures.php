<?php

// Regenerates events/korbo/fixtures/ from the kissj responses in tests/fixtures/kissj/korbo/,
// so the korbo dev event serves that realistic data through the stub provider.
//
//   docker run --rm -v "$PWD":/app -w /app php:8.3-alpine php bin/korbo-fixtures.php
//
// The programmes go through the real KissjProgramProvider, fed the responses by a Guzzle
// MockHandler, so the stub serves exactly what the kissj provider would. sections.json is
// kissj's own shape already and is copied as it is. registered.json gives the Korbo TIE
// participant, under the code below, the programmes kissj lists for them.

declare(strict_types=1);

use App\Auth\Identity;
use App\Program\KissjProgramProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

require __DIR__ . '/../vendor/autoload.php';

const TIE_CODE = 'KORBO1';

$root = dirname(__DIR__);
$source = $root . '/tests/fixtures/kissj/korbo';
$target = $root . '/events/korbo/fixtures';

$read = static function (string $file) use ($source): string {
    $body = file_get_contents($source . '/' . $file);
    if ($body === false) {
        throw new RuntimeException(sprintf('Korbo source missing: %s/%s', $source, $file));
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
$registered = array_column(
    $provider->getProgramsForIdentity(new Identity(type: 'tie', displayName: 'TIE ' . TIE_CODE, tieCode: TIE_CODE)),
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
$write('registered.json', ['tie:' . TIE_CODE => $registered]);

printf("%d programmes, %d sections, %d registered for TIE %s → %s\n", count($programs), count($sections), count($registered), TIE_CODE, $target);
