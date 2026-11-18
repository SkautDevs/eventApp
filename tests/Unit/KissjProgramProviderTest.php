<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Program\KissjProgramProvider;
use App\Program\KissjTransferException;
use App\Program\PairingStatus;
use App\Program\ProgramDataException;
use App\Program\RegistrationRefusedException;
use App\Program\SessionExpiredException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use App\Program\ProgramUnavailableException;
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

    private const array REGISTRATION = ['start' => '2027-03-15T18:00:00+01:00', 'end' => '2027-05-19T00:00:00+02:00'];

    /** a device session token as kissj mints them: 64 hex */
    private const string TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const string SESSION_HEADER = 'X-Kissj-Device-Session';

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

    /** A provider whose one call is answered with $answer. */
    private function answering(Response|\Throwable $answer): KissjProgramProvider
    {
        return $this->provider(new MockHandler([$answer]));
    }

    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /** A list response; every part can be replaced, and a null registration leaves the key out. */
    private static function listBody(array $programmes = [], array $sections = self::SECTIONS, ?array $registration = self::REGISTRATION): string
    {
        $body = ['sections' => $sections, 'programmes' => $programmes];
        if ($registration !== null) {
            $body = ['registration' => $registration] + $body;
        }

        return (string) json_encode($body);
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

    private static function session(array $participant = ['id' => 4711, 'nickname' => 'Jana'], array $programmes = []): Response
    {
        return self::json(['participant' => $participant, 'programmes' => $programmes]);
    }

    private function lastRequest(): RequestInterface
    {
        self::assertNotEmpty($this->history, 'no request was sent');

        return $this->history[array_key_last($this->history)]['request'];
    }

    /** @return mixed the request's JSON body, decoded */
    private function lastJsonBody(): mixed
    {
        return json_decode((string) $this->lastRequest()->getBody(), true);
    }

    public function testGetProgramsNormalizesShape(): void
    {
        $programs = $this->answering(new Response(200, [], self::listBody([self::programme()])))->getPrograms();

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
            'preregistered' => false,
        ], $programs[0]);
    }

    public function testAPreregisteredProgrammeSaysSo(): void
    {
        $programs = $this->answering(new Response(200, [], self::listBody([
            self::programme(['isPreregistered' => true]),
            self::programme(['id' => 6, 'isPreregistered' => false]),
            array_diff_key(self::programme(['id' => 7]), ['isPreregistered' => true]),
        ])))->getPrograms();

        self::assertSame([true, false, false], array_column($programs, 'preregistered'));
    }

    public function testGetProgramsCallsTheListEndpointWithTheApiKey(): void
    {
        self::assertSame([], $this->answering(new Response(200, [], self::listBody()))->getPrograms());

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v3/programme/list', $request->getUri()->getPath());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertFalse($request->hasHeader(self::SESSION_HEADER), 'the list is nobody\'s in particular');
    }

    public function testSectionsKeepKissjsOrderAndAreKeyedById(): void
    {
        self::assertSame([
            10 => ['id' => 10, 'title' => 'Putování', 'subTitle' => null],
            1 => ['id' => 1, 'title' => 'Hlavní program', 'subTitle' => null],
        ], $this->answering(new Response(200, [], self::listBody()))->getSections());
    }

    /**
     * The optional presentation fields map onto the names the Program screen already
     * reads. They are nullable and may be absent, and an empty string is no value either.
     */
    public function testSectionPresentationFieldsMapOntoTheScreensShape(): void
    {
        $sections = $this->answering(new Response(200, [], self::listBody(sections: [
            ['id' => 17, 'name' => 'Netradiční sporty', 'subtitle' => '1. blok'],
            ['id' => 18, 'name' => 'Mše', 'subtitle' => ''],
        ])))->getSections();

        self::assertSame(['id' => 17, 'title' => 'Netradiční sporty', 'subTitle' => '1. blok'], $sections[17]);
        self::assertSame(['id' => 18, 'title' => 'Mše', 'subTitle' => null], $sections[18]);
    }

    /** The screen asks for all three in one request, and kissj serves them in one response. */
    public function testProgrammesSectionsAndTheWindowShareOneListRequest(): void
    {
        $provider = $this->answering(new Response(200, [], self::listBody([self::programme()])));

        $provider->getPrograms();
        $provider->getSections();
        $provider->getRegistrationWindow();
        $provider->getPrograms();

        self::assertCount(1, $this->history);
    }

    public function testTheRegistrationWindowComesFromTheList(): void
    {
        $window = $this->answering(new Response(200, [], self::listBody()))->getRegistrationWindow();

        self::assertSame('2027-03-15T18:00:00+01:00', $window->start?->format(\DATE_ATOM));
        self::assertSame('2027-05-19T00:00:00+02:00', $window->end?->format(\DATE_ATOM));
        self::assertSame('Europe/Prague', $window->start?->getTimezone()->getName());
    }

    /** Either end null means "not set"; the window is still a valid answer. */
    public function testAWindowWithAMissingEndIsNotSet(): void
    {
        foreach ([['start' => null, 'end' => null], ['start' => '2027-03-15T18:00:00+01:00', 'end' => null]] as $registration) {
            $window = $this->answering(new Response(200, [], self::listBody(registration: $registration)))->getRegistrationWindow();

            self::assertSame('none', $window->state(new \DateTimeImmutable('2027-04-01T12:00:00+02:00')));
        }
    }

    public function testAListWithoutAUsableWindowIsAProviderError(): void
    {
        $bodies = [
            'no registration key' => self::listBody(registration: null),
            'registration not an object' => self::listBody(registration: ['2027-03-15T18:00:00+01:00']),
            'registration a string' => (string) json_encode(['registration' => 'open', 'sections' => self::SECTIONS, 'programmes' => []]),
            'no start key' => self::listBody(registration: ['end' => null]),
            'a number for a datetime' => self::listBody(registration: ['start' => 20270315, 'end' => null]),
            'an unparseable datetime' => self::listBody(registration: ['start' => 'soon', 'end' => null]),
        ];

        foreach ($bodies as $label => $body) {
            try {
                $this->answering(new Response(200, [], $body))->getRegistrationWindow();
                self::fail(sprintf('no provider error for %s', $label));
            } catch (ProgramDataException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEmptyPlaceAndDescriptionBecomeNull(): void
    {
        $programs = $this->answering(new Response(200, [], self::listBody([self::programme(['place' => '', 'description' => ''])])))->getPrograms();

        self::assertNull($programs[0]['location']);
        self::assertNull($programs[0]['perex']);
    }

    /**
     * A rejected key answers 401 with a plain-text body. On a call without a device session
     * that is the provider failing, worded without the URI and with nothing chained.
     */
    public function testUnauthorizedIsAProviderFailure(): void
    {
        try {
            $this->answering(new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized - unknown key'))->getPrograms();
            self::fail('a 401 did not fail');
        } catch (KissjTransferException $e) {
            self::assertSame('kissj answered HTTP 401 on the list endpoint', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
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
                $programs = $this->answering(new Response(200, [], self::listBody([
                    self::programme(['id' => 1, 'start' => '2027-06-03 08:00:00', 'end' => '2027-06-03 09:00:00']),
                    self::programme(['id' => 2, 'start' => '2027-06-03T08:00:00+00:00', 'end' => '2027-06-03T09:00:00+00:00']),
                ])))->getPrograms();

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
     * kissj is the one untrusted boundary in the app, so a 200 carrying the wrong shape has
     * to be a provider error the module can catch — not a TypeError five frames in, which
     * is a 500 on /programy.
     */
    public function testAMalformedPayloadIsAProviderErrorAndNotACrash(): void
    {
        $list = static fn(array $programmes): string => self::listBody($programmes);
        $withSections = static fn(mixed $sections): string => (string) json_encode([
            'registration' => self::REGISTRATION, 'sections' => $sections, 'programmes' => [],
        ]);
        $bodies = [
            'bare list of scalars' => json_encode([1, 2, 3]),
            'bare list of programmes' => json_encode([self::programme()]),
            'not JSON' => '<html>maintenance</html>',
            'empty body' => '',
            'no programmes key' => json_encode(['registration' => self::REGISTRATION, 'sections' => self::SECTIONS, 'programs' => [self::programme()]]),
            'programmes not a list' => json_encode(['registration' => self::REGISTRATION, 'sections' => self::SECTIONS, 'programmes' => 'none']),
            'programmes a map' => json_encode(['registration' => self::REGISTRATION, 'sections' => self::SECTIONS, 'programmes' => ['a' => self::programme()]]),
            'record without id' => $list([['name' => 'Bez id', 'sectionId' => 1]]),
            'record without name' => $list([['id' => 1, 'sectionId' => 1]]),
            'record without sectionId' => $list([array_diff_key(self::programme(), ['sectionId' => true])]),
            'non-int sectionId' => $list([self::programme(['sectionId' => '10'])]),
            'null sectionId' => $list([self::programme(['sectionId' => null])]),
            'non-bool isPreregistered' => $list([self::programme(['isPreregistered' => 'yes'])]),
            'no sections key' => json_encode(['registration' => self::REGISTRATION, 'programmes' => [self::programme()]]),
            'no registration key' => self::listBody([self::programme()], registration: null),
            'sections not a list' => $withSections('none'),
            'sections a map' => $withSections(['a' => self::SECTIONS[0]]),
            'section not an object' => $withSections([10]),
            'section without id' => $withSections([['name' => 'Bez id']]),
            'section with a non-int id' => $withSections([['id' => '10', 'name' => 'Putování']]),
            'section without name' => $withSections([['id' => 10]]),
            'section with a non-string name' => $withSections([['id' => 10, 'name' => 7]]),
            'section with a non-string subtitle' => $withSections([['id' => 10, 'name' => 'P', 'subtitle' => 3]]),
        ];

        foreach ($bodies as $label => $body) {
            foreach (['getPrograms', 'getSections', 'getRegistrationWindow'] as $method) {
                try {
                    $this->answering(new Response(200, [], (string) $body))->$method();
                    self::fail(sprintf('%s: no provider error for %s', $method, $label));
                } catch (ProgramDataException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function testTheSessionReadSendsTheDeviceSessionAndMapsTheParticipant(): void
    {
        $participant = $this->answering(self::session(['id' => 4711, 'nickname' => 'Jana'], [self::programme([
            'id' => 7,
            'sectionId' => 1,
            'start' => '2027-06-03T15:00:00+02:00',
            'end' => '2027-06-03T16:00:00+02:00',
            'isPreregistered' => true,
        ])]))->getParticipant(self::TOKEN);

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v3/programme/participant/session', $request->getUri()->getPath());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame(self::TOKEN, $request->getHeaderLine(self::SESSION_HEADER));
        self::assertStringNotContainsString(self::TOKEN, (string) $request->getUri(), 'the token travels in the header only');

        self::assertSame(4711, $participant->id);
        self::assertSame('Jana', $participant->nickname);
        self::assertSame([7], array_column($participant->programmes, 'id'));
        self::assertSame('2027-06-03 15:00:00', $participant->programmes[0]['start']['date']);
        self::assertSame(['id' => 1], $participant->programmes[0]['section']);
        self::assertTrue($participant->programmes[0]['preregistered']);
    }

    public function testANullNicknameStaysNull(): void
    {
        foreach ([['id' => 1, 'nickname' => null], ['id' => 1], ['id' => 1, 'nickname' => '']] as $record) {
            self::assertNull($this->answering(self::session($record))->getParticipant(self::TOKEN)->nickname);
        }
    }

    public function testAParticipantWithoutAUsableIdIsAProviderError(): void
    {
        $bodies = [
            'no id' => ['participant' => ['nickname' => 'Jana'], 'programmes' => []],
            'a string id' => ['participant' => ['id' => '4711', 'nickname' => 'Jana'], 'programmes' => []],
            'a float id' => ['participant' => ['id' => 4711.5], 'programmes' => []],
            'id 0' => ['participant' => ['id' => 0], 'programmes' => []],
            'a negative id' => ['participant' => ['id' => -3], 'programmes' => []],
            'no participant' => ['programmes' => []],
            'participant not an object' => ['participant' => 4711, 'programmes' => []],
            'a nickname that is a number' => ['participant' => ['id' => 4711, 'nickname' => 7], 'programmes' => []],
            'no programmes key' => ['participant' => ['id' => 4711]],
            'programmes not a list' => ['participant' => ['id' => 4711], 'programmes' => 7],
            'bare list' => [self::programme()],
        ];

        foreach ($bodies as $label => $body) {
            try {
                $this->answering(self::json($body))->getParticipant(self::TOKEN);
                self::fail(sprintf('no provider error for %s', $label));
            } catch (ProgramDataException $e) {
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage(), $label);
            }
        }
    }

    /** The contract's "session gone": a 401 with an empty body, and the token in no message. */
    public function testA401OnASessionCallIsAnExpiredSession(): void
    {
        $calls = [
            'getParticipant' => static fn(KissjProgramProvider $p) => $p->getParticipant(self::TOKEN),
            'register' => static fn(KissjProgramProvider $p) => $p->register(self::TOKEN, 5),
            'unregister' => static fn(KissjProgramProvider $p) => $p->unregister(self::TOKEN, 5),
            'revokeSession' => static fn(KissjProgramProvider $p) => $p->revokeSession(self::TOKEN),
        ];

        foreach ($calls as $label => $call) {
            try {
                $call($this->answering(new Response(401)));
                self::fail(sprintf('%s: a 401 did not expire the session', $label));
            } catch (SessionExpiredException $e) {
                self::assertSame('Device session is no longer valid', $e->getMessage(), $label);
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage(), $label);
                self::assertNull($e->getPrevious(), $label);
            }
        }
    }

    /**
     * A rejected API key is a 401 too, but with kissj's plain-text body. That is the
     * provider failing, not the reader's session: taking it for one would log out every
     * reader of the event at once.
     */
    public function testA401WithABodyOnASessionCallIsAnOutage(): void
    {
        try {
            $this->answering(new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized - unknown key'))->getParticipant(self::TOKEN);
            self::fail('a 401 with a body did not fail');
        } catch (KissjTransferException $e) {
            self::assertSame('kissj answered HTTP 401 on the session endpoint', $e->getMessage());
        }
    }

    /** Every message — and every chained one — reaches logs and Sentry. */
    public function testAFailedSessionCallCarriesTheTokenInNoMessage(): void
    {
        $uri = 'https://kissj.example/v3/programme/participant/session';
        $expected = [
            'HTTP 500' => 'kissj answered HTTP 500 on the session endpoint',
            'HTTP 404' => 'kissj answered HTTP 404 on the session endpoint',
            'timeout' => 'kissj could not be reached on the session endpoint (ConnectException)',
            'not JSON' => 'kissj sent something other than a JSON object for the session endpoint',
        ];
        foreach ([
            'HTTP 500' => new Response(500, [], 'boom ' . self::TOKEN),
            'HTTP 404' => new Response(404, [], ''),
            'timeout' => new ConnectException('cURL error 28: Operation timed out for ' . $uri, new Request('GET', $uri, [self::SESSION_HEADER => self::TOKEN])),
            'not JSON' => new Response(200, [], 'not json'),
        ] as $case => $answer) {
            try {
                $this->answering($answer)->getParticipant(self::TOKEN);
                self::fail($case . ' did not fail');
            } catch (ProgramUnavailableException|ProgramDataException $e) {
                self::assertSame($expected[$case], $e->getMessage(), $case);
                self::assertNull($e->getPrevious(), $case);
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage(), $case);
            }
        }
    }

    public function testParticipantIdsForAProgrammeComeFromTheParticipantsEndpoint(): void
    {
        $ids = $this->answering(self::json(['participantIds' => [4711, 4712, 4711]]))->getParticipantIdsForProgramme(42);

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v3/programme/42/participants', $request->getUri()->getPath());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame([4711, 4712], $ids);
    }

    public function testAnEmptyListOfParticipantsIsAnAnswer(): void
    {
        self::assertSame([], $this->answering(self::json(['participantIds' => []]))->getParticipantIdsForProgramme(42));
    }

    public function testParticipantsThatAreNotAListOfIdsAreAProviderError(): void
    {
        $bodies = [
            'the 1.0 answer' => ['tieCodes' => ['KORBO1']],
            'a number' => ['participantIds' => 4711],
            'a string in the list' => ['participantIds' => ['4711']],
            'a float in the list' => ['participantIds' => [4711.5]],
            'a map' => ['participantIds' => ['a' => 4711]],
            'no key' => [],
        ];

        foreach ($bodies as $label => $body) {
            try {
                $this->answering(self::json($body))->getParticipantIdsForProgramme(42);
                self::fail(sprintf('no provider error for %s', $label));
            } catch (ProgramDataException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAFailedParticipantsCallSurfacesAsAnOutage(): void
    {
        foreach ([new Response(500), new Response(404)] as $answer) {
            try {
                $this->answering($answer)->getParticipantIdsForProgramme(42);
                self::fail('the failure was swallowed');
            } catch (KissjTransferException $e) {
                self::assertStringContainsString('on the programme participants endpoint', $e->getMessage());
            }
        }
    }

    public function testStartingAPairingSendsTheDeviceIdAndMapsTheAnswer(): void
    {
        $deviceId = str_repeat('ab', 32);
        $pairing = $this->answering(self::json([
            'deviceCode' => '0123456789abcdef0123456789abcdef',
            'userCode' => '483291',
            'verificationUrl' => 'https://kissj.example/v2/event/obrok27/participant/dashboard#devicePairing',
            'expiresAt' => '2027-05-20T09:00:00+02:00',
            'interval' => 5,
        ]))->startPairing($deviceId);

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v3/programme/pairing/start', $request->getUri()->getPath());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame(['deviceId' => $deviceId], $this->lastJsonBody());
        self::assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
        self::assertFalse($request->hasHeader(self::SESSION_HEADER));

        self::assertSame('0123456789abcdef0123456789abcdef', $pairing->deviceCode);
        self::assertSame('483291', $pairing->userCode);
        self::assertSame('483 291', $pairing->formattedUserCode());
        self::assertSame(
            'https://kissj.example/v2/event/obrok27/participant/dashboard#devicePairing',
            $pairing->verificationUrl,
        );
        self::assertSame('2027-05-20T09:00:00+02:00', $pairing->expiresAt->format(\DATE_ATOM));
        self::assertSame(5, $pairing->interval);
    }

    public function testAMalformedPairingStartIsAProviderError(): void
    {
        $valid = [
            'deviceCode' => '0123456789abcdef0123456789abcdef',
            'userCode' => '483291',
            'verificationUrl' => 'https://kissj.example/pairing',
            'expiresAt' => '2027-05-20T09:00:00+02:00',
            'interval' => 5,
        ];
        $bodies = [
            'no deviceCode' => array_diff_key($valid, ['deviceCode' => true]),
            'an empty deviceCode' => ['deviceCode' => ''] + $valid,
            'a numeric userCode' => ['userCode' => 483291] + $valid,
            'no verificationUrl' => array_diff_key($valid, ['verificationUrl' => true]),
            // the page renders it as a link: only an absolute web address may become one
            'a script URL' => ['verificationUrl' => 'javascript:alert(1)'] + $valid,
            'a relative URL' => ['verificationUrl' => '/pairing'] + $valid,
            'an unparseable expiry' => ['expiresAt' => 'in an hour'] + $valid,
            'a string interval' => ['interval' => '5'] + $valid,
        ];

        foreach ($bodies as $label => $body) {
            try {
                $this->answering(self::json($body))->startPairing(str_repeat('ab', 32));
                self::fail(sprintf('no provider error for %s', $label));
            } catch (ProgramDataException) {
                self::assertTrue(true);
            }
        }
    }

    /** kissj's cap of pending pairings is plain text: an outage like any other. */
    public function testATooManyPairingsAnswerIsATransferFailure(): void
    {
        try {
            $this->answering(new Response(429, ['Content-Type' => 'text/plain'], 'Too many pending pairings'))->startPairing(str_repeat('ab', 32));
            self::fail('a 429 did not fail');
        } catch (KissjTransferException $e) {
            self::assertSame('kissj answered HTTP 429 on the pairing start endpoint', $e->getMessage());
        }
    }

    public function testPollingSendsTheDeviceCodeAndMapsEveryStatus(): void
    {
        foreach (['pending' => PairingStatus::Pending, 'slowDown' => PairingStatus::SlowDown, 'expired' => PairingStatus::Expired] as $wire => $status) {
            $poll = $this->answering(self::json(['status' => $wire]))->pollPairing('0123456789abcdef0123456789abcdef');

            self::assertSame($status, $poll->status, $wire);
            self::assertNull($poll->sessionToken, $wire);
            self::assertNull($poll->sessionExpiresAt, $wire);
        }

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v3/programme/pairing/poll', $request->getUri()->getPath());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame(['deviceCode' => '0123456789abcdef0123456789abcdef'], $this->lastJsonBody());
    }

    public function testAnApprovalCarriesTheTokenAndItsExpiry(): void
    {
        $poll = $this->answering(self::json([
            'status' => 'approved',
            'sessionToken' => self::TOKEN,
            'expiresAt' => '2027-11-20T08:00:00+01:00',
        ]))->pollPairing('0123456789abcdef0123456789abcdef');

        self::assertSame(PairingStatus::Approved, $poll->status);
        self::assertSame(self::TOKEN, $poll->sessionToken);
        self::assertSame('2027-11-20T08:00:00+01:00', $poll->sessionExpiresAt?->format(\DATE_ATOM));
    }

    public function testAMalformedPollIsAProviderErrorThatNamesNoToken(): void
    {
        $bodies = [
            'no status' => [],
            'an unknown status' => ['status' => 'authorization_pending'],
            'the removed denied status' => ['status' => 'denied'],
            'approved without a token' => ['status' => 'approved', 'expiresAt' => '2027-11-20T08:00:00+01:00'],
            'approved with an empty token' => ['status' => 'approved', 'sessionToken' => '', 'expiresAt' => '2027-11-20T08:00:00+01:00'],
            'approved without an expiry' => ['status' => 'approved', 'sessionToken' => self::TOKEN],
            'approved with a broken expiry' => ['status' => 'approved', 'sessionToken' => self::TOKEN, 'expiresAt' => 'never'],
        ];

        foreach ($bodies as $label => $body) {
            try {
                $this->answering(self::json($body))->pollPairing('0123456789abcdef0123456789abcdef');
                self::fail(sprintf('no provider error for %s', $label));
            } catch (ProgramDataException $e) {
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage(), $label);
            }
        }
    }

    public function testRegisterAndUnregisterPostNoBodyUnderBothHeaders(): void
    {
        foreach (['register', 'unregister'] as $verb) {
            $result = $this->answering(self::json(['ok' => true]))->$verb(self::TOKEN, 42);

            self::assertNull($result, $verb);
            $request = $this->lastRequest();
            self::assertSame('POST', $request->getMethod(), $verb);
            self::assertSame('/v3/programme/42/' . $verb, $request->getUri()->getPath(), $verb);
            self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'), $verb);
            self::assertSame(self::TOKEN, $request->getHeaderLine(self::SESSION_HEADER), $verb);
            self::assertSame('', (string) $request->getBody(), $verb);
        }
    }

    public function testARefusalCarriesKissjsCode(): void
    {
        foreach (['register' => 'full', 'unregister' => 'notRegistered'] as $verb => $code) {
            try {
                $this->answering(self::json(['error' => $code], 400))->$verb(self::TOKEN, 42);
                self::fail(sprintf('%s: a refusal did not throw', $verb));
            } catch (RegistrationRefusedException $e) {
                self::assertSame($code, $e->refusal, $verb);
                self::assertSame('kissj refused the registration: ' . $code, $e->getMessage(), $verb);
            }
        }
    }

    /** Only the contract's JSON shape is a refusal; any other 400 is the provider failing. */
    public function testA400WithoutTheErrorShapeIsAnOutage(): void
    {
        foreach ([
            'plain text' => new Response(400, ['Content-Type' => 'text/plain'], 'Bad Request'),
            'no error key' => self::json(['message' => 'full'], 400),
            'an empty code' => self::json(['error' => ''], 400),
            'a numeric code' => self::json(['error' => 7], 400),
        ] as $label => $answer) {
            try {
                $this->answering($answer)->register(self::TOKEN, 42);
                self::fail(sprintf('no failure for %s', $label));
            } catch (KissjTransferException $e) {
                self::assertSame('kissj answered HTTP 400 on the register endpoint', $e->getMessage(), $label);
            }
        }
    }

    public function testAWriteWithoutOkIsAProviderError(): void
    {
        foreach ([['ok' => false], [], ['ok' => 'true']] as $body) {
            try {
                $this->answering(self::json($body))->register(self::TOKEN, 42);
                self::fail('no provider error for ' . json_encode($body));
            } catch (ProgramDataException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRevokingPostsUnderTheSessionHeader(): void
    {
        $this->answering(self::json(['ok' => true]))->revokeSession(self::TOKEN);

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v3/programme/session/revoke', $request->getUri()->getPath());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame(self::TOKEN, $request->getHeaderLine(self::SESSION_HEADER));
        self::assertSame('', (string) $request->getBody());
    }

    /** Swallowing is the caller's choice (logout is best effort): the provider reports every failure. */
    public function testRevokingSwallowsNothingButA200(): void
    {
        foreach ([
            'HTTP 500' => [new Response(500), KissjTransferException::class],
            'timeout' => [new ConnectException('down', new Request('POST', 'v3/programme/session/revoke')), KissjTransferException::class],
            'not JSON' => [new Response(200, [], 'ok'), ProgramDataException::class],
        ] as $label => [$answer, $expected]) {
            try {
                $this->answering($answer)->revokeSession(self::TOKEN);
                self::fail(sprintf('%s was swallowed', $label));
            } catch (\Throwable $e) {
                self::assertInstanceOf($expected, $e, $label);
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage(), $label);
            }
        }
    }
}
