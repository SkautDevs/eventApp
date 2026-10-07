<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;
use App\Program\KissjProgramProvider;
use App\Program\KissjTransferException;
use App\Program\ProgramDataException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class KissjProgramProviderTest extends TestCase
{
    private const array SECTIONS = [['id' => 10, 'name' => 'Putování'], ['id' => 1, 'name' => 'Hlavní program']];

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private function provider(MockHandler $mock): KissjProgramProvider
    {
        $this->history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new KissjProgramProvider(
            http: new Client(['handler' => $stack, 'base_uri' => 'https://kissj.example/']),
            apiKey: 'secret-key',
        );
    }

    private static function programme(array $overrides = []): array
    {
        return $overrides + [
            'id' => 5,
            'name' => 'Ukázková vycházka',
            'sectionId' => 10,
            'description' => 'Perex programu.',
            'place' => 'Sraz u brány',
            'start' => '2027-06-03T08:00:00+02:00',
            'end' => '2027-06-03T12:00:00+02:00',
            'isPreregistered' => false,
            'targetRoles' => ['ist', 'guest'],
        ];
    }

    private function lastRequest(): RequestInterface
    {
        self::assertNotEmpty($this->history, 'no request was sent');

        return $this->history[array_key_last($this->history)]['request'];
    }

    public function testGetProgramsNormalizesShape(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['sections' => self::SECTIONS, 'programmes' => [self::programme()]])),
        ]);

        $programs = $this->provider($mock)->getPrograms();

        self::assertSame([
            'id' => 5,
            'name' => 'Ukázková vycházka',
            'section' => ['id' => 10],
            'start' => ['date' => '2027-06-03 08:00:00'],
            'end' => ['date' => '2027-06-03 12:00:00'],
            'lector' => null,
            'location' => 'Sraz u brány',
            'perex' => 'Perex programu.',
            'tools' => null,
        ], $programs[0]);
    }

    public function testGetProgramsCallsTheListEndpointWithTheApiKey(): void
    {
        $mock = new MockHandler([new Response(200, [], (string) json_encode(['sections' => self::SECTIONS, 'programmes' => []]))]);

        self::assertSame([], $this->provider($mock)->getPrograms());

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v3/programme/list', $request->getUri()->getPath());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
    }

    public function testParticipantEndpointsAreKeyedByTieCodeWithTheApiKey(): void
    {
        $body = (string) json_encode(['participant' => ['nickname' => null], 'programmes' => []]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $this->provider($mock)->getProgramsForIdentity(
            new Identity(displayName: 'TIE', tieCode: 'AB/C 1'),
        );
        self::assertSame('/v3/programme/participant/tie/AB%2FC%201', $this->lastRequest()->getUri()->getPath());
        self::assertSame('Bearer secret-key', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testSectionsKeepKissjsOrderAndAreKeyedById(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['sections' => self::SECTIONS, 'programmes' => []])),
        ]);

        self::assertSame([
            10 => ['id' => 10, 'title' => 'Putování', 'subTitle' => null, 'image' => null, 'attachment' => null],
            1 => ['id' => 1, 'title' => 'Hlavní program', 'subTitle' => null, 'image' => null, 'attachment' => null],
        ], $this->provider($mock)->getSections());
    }

    /**
     * The optional presentation fields map onto the names the Program screen already
     * reads. They are nullable and may be absent, and an empty string is no value either.
     */
    public function testSectionPresentationFieldsMapOntoTheScreensShape(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['programmes' => [], 'sections' => [
                [
                    'id' => 17,
                    'name' => 'Netradiční sporty',
                    'subtitle' => '1. blok',
                    'imageUrl' => 'https://kissj.example/img/map.png',
                    'attachment' => ['url' => 'https://kissj.example/files/rules.pdf', 'label' => 'Pravidla'],
                ],
                ['id' => 18, 'name' => 'Mše', 'subtitle' => '', 'imageUrl' => null, 'attachment' => null],
            ]])),
        ]);

        $sections = $this->provider($mock)->getSections();

        self::assertSame([
            'id' => 17,
            'title' => 'Netradiční sporty',
            'subTitle' => '1. blok',
            'image' => 'https://kissj.example/img/map.png',
            'attachment' => ['href' => 'https://kissj.example/files/rules.pdf', 'label' => 'Pravidla'],
        ], $sections[17]);
        self::assertSame(['id' => 18, 'title' => 'Mše', 'subTitle' => null, 'image' => null, 'attachment' => null], $sections[18]);
    }

    /** The screen asks for both in one request, and kissj serves both in one response. */
    public function testProgrammesAndSectionsShareOneListRequest(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['sections' => self::SECTIONS, 'programmes' => [self::programme()]])),
        ]);
        $provider = $this->provider($mock);

        $provider->getPrograms();
        $provider->getSections();
        $provider->getPrograms();

        self::assertCount(1, $this->history);
    }

    public function testEmptyPlaceAndDescriptionBecomeNull(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['sections' => self::SECTIONS, 'programmes' => [self::programme(['place' => '', 'description' => ''])]])),
        ]);

        $programs = $this->provider($mock)->getPrograms();

        self::assertNull($programs[0]['location']);
        self::assertNull($programs[0]['perex']);
    }

    public function testUnknownTieCodeThrows(): void
    {
        $mock = new MockHandler([
            new RequestException('Not Found', new Request('GET', 'x'), new Response(404)),
        ]);

        try {
            $this->provider($mock)->getProgramsForIdentity(new Identity(displayName: 'TIE ABC', tieCode: 'ABC'));
            self::fail('an unknown code must throw');
        } catch (UnknownParticipantException $e) {
            // the message reaches logs and Sentry; the code must not
            self::assertSame('Unknown TIE code', $e->getMessage());
            // nor may a chained exception carry it: Guzzle's message names the URI
            self::assertNull($e->getPrevious());
        }
    }

    public function testA404WithABodyIsAnOutageNotAnUnknownCode(): void
    {
        $provider = $this->provider(new MockHandler([new Response(404, ['Content-Type' => 'text/html'], '<html><body>Maintenance</body></html>')]));

        $this->expectException(KissjTransferException::class);
        $this->expectExceptionMessage('kissj answered HTTP 404 on the participant endpoint');
        $provider->getProgramsForIdentity(new Identity('TIE X', 'KORBO1'));
    }

    /**
     * A rejected key answers 401 with a plain-text body. That is not a 404, so it is
     * neither "unknown participant" nor "no registrations" — it is the provider failing.
     * The list endpoint surfaces Guzzle's own exception; the participant endpoint's path
     * carries the TIE code, so its failure is rewrapped into a TransferException without it.
     */
    public function testUnauthorizedIsAProviderFailure(): void
    {
        $answer = static fn (): Response => new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized - unknown key');

        try {
            $this->provider(new MockHandler([$answer()]))->getPrograms();
            self::fail('a 401 did not fail');
        } catch (ClientException $e) {
            self::assertSame(401, $e->getResponse()->getStatusCode());
        }

        try {
            $this->provider(new MockHandler([$answer()]))->getProgramsForIdentity(new Identity(displayName: 'TIE', tieCode: 'ABC'));
            self::fail('a 401 did not fail');
        } catch (TransferException $e) {
            self::assertSame('kissj answered HTTP 401 on the participant endpoint', $e->getMessage());
        }
    }

    public function testTieProgramsAreNormalized(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'participant' => ['nickname' => 'Jana'],
                'programmes' => [self::programme([
                    'id' => 7,
                    'name' => 'Program',
                    'sectionId' => 1,
                    'start' => '2027-06-03T15:00:00+02:00',
                    'end' => '2027-06-03T16:00:00+02:00',
                ])],
            ])),
        ]);

        $programs = $this->provider($mock)->getProgramsForIdentity(
            new Identity(displayName: 'TIE ABC', tieCode: 'ABC'),
        );

        self::assertSame(7, $programs[0]['id']);
        self::assertSame(1, $programs[0]['section']['id']);
        self::assertSame('2027-06-03 15:00:00', $programs[0]['start']['date']);
    }

    /**
     * The times the screen shows are the event's own wall clock, so a datetime that
     * carries no offset — the most likely thing a PHP app on the other end emits — is
     * read as Prague and not as whatever zone the server happens to run in. Inheriting
     * the process default moved every programme two hours in summer, and nothing in the
     * suite could see it: both the reading and the formatting were shifted alike.
     */
    public function testDatetimesAreAnchoredToTheEventsZoneAndNotTheServers(): void
    {
        foreach (['UTC', 'America/New_York'] as $serverZone) {
            $previous = date_default_timezone_get();
            date_default_timezone_set($serverZone);

            try {
                $mock = new MockHandler([
                    new Response(200, [], (string) json_encode(['sections' => self::SECTIONS, 'programmes' => [
                        self::programme(['id' => 1, 'start' => '2027-06-03 08:00:00', 'end' => '2027-06-03 09:00:00']),
                        self::programme(['id' => 2, 'start' => '2027-06-03T08:00:00+00:00', 'end' => '2027-06-03T09:00:00+00:00']),
                    ]])),
                ]);

                $programs = $this->provider($mock)->getPrograms();

                // no offset: taken at face value, as the event's own clock
                self::assertSame('2027-06-03 08:00:00', $programs[0]['start']['date'], $serverZone);
                // an offset it does carry is converted to that same clock
                self::assertSame('2027-06-03 10:00:00', $programs[1]['start']['date'], $serverZone);
            } finally {
                date_default_timezone_set($previous);
            }
        }
    }

    /**
     * kissj is the one untrusted boundary in the app and does not serve the contract yet,
     * so a 200 carrying the wrong shape has to be a provider error the module can catch —
     * not a TypeError five frames in, which is a 500 on /programy.
     */
    public function testAMalformedPayloadIsAProviderErrorAndNotACrash(): void
    {
        $list = static fn (array $programmes): string => (string) json_encode(['sections' => self::SECTIONS, 'programmes' => $programmes]);
        $bodies = [
            'bare list of scalars' => json_encode([1, 2, 3]),
            'bare list of programmes' => json_encode([self::programme()]),
            'not JSON' => '<html>maintenance</html>',
            'empty body' => '',
            'no programmes key' => json_encode(['sections' => self::SECTIONS, 'programs' => [self::programme()]]),
            'programmes not a list' => json_encode(['sections' => self::SECTIONS, 'programmes' => 'none']),
            'programmes a map' => json_encode(['sections' => self::SECTIONS, 'programmes' => ['a' => self::programme()]]),
            'record without id' => $list([['name' => 'Bez id', 'sectionId' => 1]]),
            'record without name' => $list([['id' => 1, 'sectionId' => 1]]),
            'record without sectionId' => $list([array_diff_key(self::programme(), ['sectionId' => true])]),
            'non-int sectionId' => $list([self::programme(['sectionId' => '10'])]),
            'null sectionId' => $list([self::programme(['sectionId' => null])]),
            'no sections key' => json_encode(['programmes' => [self::programme()]]),
            'sections not a list' => json_encode(['sections' => 'none', 'programmes' => []]),
            'sections a map' => json_encode(['sections' => ['a' => self::SECTIONS[0]], 'programmes' => []]),
            'section not an object' => json_encode(['sections' => [10], 'programmes' => []]),
            'section without id' => json_encode(['sections' => [['name' => 'Bez id']], 'programmes' => []]),
            'section with a non-int id' => json_encode(['sections' => [['id' => '10', 'name' => 'Putování']], 'programmes' => []]),
            'section without name' => json_encode(['sections' => [['id' => 10]], 'programmes' => []]),
            'section with a non-string name' => json_encode(['sections' => [['id' => 10, 'name' => 7]], 'programmes' => []]),
            'section with a non-string subtitle' => json_encode(['sections' => [['id' => 10, 'name' => 'P', 'subtitle' => 3]], 'programmes' => []]),
            'section with a non-string imageUrl' => json_encode(['sections' => [['id' => 10, 'name' => 'P', 'imageUrl' => ['x']]], 'programmes' => []]),
            'attachment not an object' => json_encode(['sections' => [['id' => 10, 'name' => 'P', 'attachment' => 'x.pdf']], 'programmes' => []]),
            'attachment without url' => json_encode(['sections' => [['id' => 10, 'name' => 'P', 'attachment' => ['label' => 'L']]], 'programmes' => []]),
            'attachment without label' => json_encode(['sections' => [['id' => 10, 'name' => 'P', 'attachment' => ['url' => 'https://k.example/a.pdf']]], 'programmes' => []]),
            // the attachment becomes an <a href>, so a scheme that runs script is not a link
            'attachment with a javascript: url' => json_encode(['sections' => [['id' => 10, 'name' => 'P', 'attachment' => ['url' => 'javascript:alert(1)', 'label' => 'L']]], 'programmes' => []]),
            'image with a data: url' => json_encode(['sections' => [['id' => 10, 'name' => 'P', 'imageUrl' => 'data:image/svg+xml,<svg/>']], 'programmes' => []]),
        ];

        foreach ($bodies as $label => $body) {
            foreach (['getPrograms', 'getSections'] as $method) {
                $mock = new MockHandler([new Response(200, [], (string) $body)]);

                try {
                    $this->provider($mock)->$method();
                    self::fail(sprintf('%s: no provider error for %s', $method, $label));
                } catch (ProgramDataException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function testAMalformedParticipantPayloadIsAProviderError(): void
    {
        $bodies = [
            'bare list' => json_encode([self::programme()]),
            'no programmes key' => json_encode(['participant' => ['nickname' => 'Jana']]),
            'programmes not a list' => json_encode(['participant' => [], 'programmes' => 7]),
        ];

        foreach ($bodies as $label => $body) {
            $mock = new MockHandler([new Response(200, [], (string) $body)]);

            try {
                $this->provider($mock)->getProgramsForIdentity(
                    new Identity(displayName: 'TIE ABC', tieCode: 'ABC'),
                );
                self::fail(sprintf('no provider error for %s', $label));
            } catch (ProgramDataException) {
                self::assertTrue(true);
            }
        }
    }

    /** The participant path carries the code, and exception messages reach logs and Sentry. */
    public function testAMalformedParticipantPayloadKeepsTheTieCodeOutOfTheMessage(): void
    {
        $bodies = [
            'not JSON' => 'not json',
            'bare list' => json_encode([self::programme()]),
            'no programmes key' => json_encode(['participant' => ['nickname' => 'Jana']]),
        ];

        foreach ($bodies as $label => $body) {
            $mock = new MockHandler([new Response(200, [], (string) $body)]);

            try {
                $this->provider($mock)->getProgramsForIdentity(
                    new Identity(displayName: 'TIE TAJNY7', tieCode: 'TAJNY7'),
                );
                self::fail(sprintf('no provider error for %s', $label));
            } catch (ProgramDataException $e) {
                self::assertStringNotContainsString('TAJNY7', $e->getMessage(), $label);
                self::assertStringContainsString('participant endpoint', $e->getMessage(), $label);
            }
        }
    }

    public function testTieCodesForAProgrammeComeFromTheParticipantsEndpoint(): void
    {
        $mock = new MockHandler([new Response(200, [], (string) json_encode(['tieCodes' => ['korbo1', 'KORBO2']]))]);

        $codes = $this->provider($mock)->getTieCodesForProgramme(42);

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v3/programme/42/participants', $request->getUri()->getPath());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame(['KORBO1', 'KORBO2'], $codes);
    }

    public function testParticipantsThatAreNotAListOfStringsAreAProviderError(): void
    {
        $bodies = [
            'a string' => ['tieCodes' => 'KORBO1'],
            'a number in the list' => ['tieCodes' => [1]],
            'an empty code' => ['tieCodes' => ['']],
            'a map' => ['tieCodes' => ['a' => 'KORBO1']],
            'no key' => [],
        ];

        foreach ($bodies as $label => $body) {
            $mock = new MockHandler([new Response(200, [], (string) json_encode($body))]);

            try {
                $this->provider($mock)->getTieCodesForProgramme(42);
                self::fail(sprintf('no provider error for %s', $label));
            } catch (ProgramDataException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAFailedParticipantsCallSurfacesAsATransferException(): void
    {
        $mock = new MockHandler([new Response(500)]);

        $this->expectException(TransferException::class);
        $this->provider($mock)->getTieCodesForProgramme(42);
    }

    /** Every message — and every chained one — reaches logs and Sentry; the code is the participant's secret. */
    public function testAFailedParticipantCallCarriesTheCodeInNoMessage(): void
    {
        $uri = 'https://kissj.example/v3/programme/participant/tie/SECRET7';
        $expected = [
            'HTTP 500' => 'kissj answered HTTP 500 on the participant endpoint',
            'timeout' => 'kissj could not be reached on the participant endpoint (ConnectException)',
        ];
        foreach ([
            'HTTP 500' => new Response(500, [], 'boom'),
            'timeout' => new ConnectException('cURL error 28: Operation timed out for ' . $uri, new Request('GET', $uri)),
        ] as $case => $answer) {
            try {
                $this->provider(new MockHandler([$answer]))->getProgramsForIdentity(new Identity(displayName: 'TIE SECRET7', tieCode: 'SECRET7'));
                self::fail($case . ' did not fail');
            } catch (TransferException $e) {
                // still a TransferException, so every existing catch site keeps degrading on it
                self::assertInstanceOf(KissjTransferException::class, $e, $case);
                self::assertSame($expected[$case], $e->getMessage(), $case);
                self::assertNull($e->getPrevious(), $case);
                self::assertStringNotContainsString('SECRET7', $e->getMessage(), $case);
            }
        }
    }
}
