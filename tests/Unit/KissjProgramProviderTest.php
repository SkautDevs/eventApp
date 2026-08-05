<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;
use App\Program\KissjProgramProvider;
use App\Program\ProgramDataException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class KissjProgramProviderTest extends TestCase
{
    private function provider(MockHandler $mock): KissjProgramProvider
    {
        return new KissjProgramProvider(
            http: new Client(['handler' => HandlerStack::create($mock)]),
            eventSlug: 'obrok27',
        );
    }

    public function testGetProgramsNormalizesShape(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([[
                'id' => 5,
                'name' => 'Ukázková vycházka',
                'sectionId' => 10,
                'start' => '2027-06-03T08:00:00+02:00',
                'end' => '2027-06-03T12:00:00+02:00',
                'lector' => 'Jana Testová',
                'location' => 'Sraz u brány',
                'description' => 'Perex programu.',
                'tools' => null,
            ]])),
        ]);

        $programs = $this->provider($mock)->getPrograms();

        self::assertSame(10, $programs[0]['section']['id']);
        self::assertSame('2027-06-03 08:00:00', $programs[0]['start']['date']);
        self::assertSame('Perex programu.', $programs[0]['perex']);
        self::assertNull($programs[0]['tools']);
    }

    public function testUnknownTieCodeThrows(): void
    {
        $mock = new MockHandler([
            new RequestException('Not Found', new Request('GET', 'x'), new Response(404)),
        ]);

        $this->expectException(UnknownParticipantException::class);
        $this->provider($mock)->getProgramsForIdentity(
            new Identity(type: 'tie', displayName: 'TIE ABC', tieCode: 'ABC'),
        );
    }

    public function testUnknownSkautisUserReturnsEmpty(): void
    {
        $mock = new MockHandler([
            new RequestException('Not Found', new Request('GET', 'x'), new Response(404)),
        ]);

        $programs = $this->provider($mock)->getProgramsForIdentity(
            new Identity(type: 'skautis', displayName: 'Nikdo', skautisUserId: 999),
        );

        self::assertSame([], $programs);
    }

    public function testTieProgramsAreNormalized(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'participant' => ['nickname' => 'Jana'],
                'programs' => [[
                    'id' => 7,
                    'name' => 'Program',
                    'sectionId' => 1,
                    'start' => '2027-06-03T15:00:00+02:00',
                    'end' => '2027-06-03T16:00:00+02:00',
                ]],
            ])),
        ]);

        $programs = $this->provider($mock)->getProgramsForIdentity(
            new Identity(type: 'tie', displayName: 'TIE ABC', tieCode: 'ABC'),
        );

        self::assertSame(7, $programs[0]['id']);
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
                    new Response(200, [], (string) json_encode([
                        ['id' => 1, 'name' => 'Naivní', 'sectionId' => 1, 'start' => '2027-06-03 08:00:00', 'end' => '2027-06-03 09:00:00'],
                        ['id' => 2, 'name' => 'S posunem', 'sectionId' => 1, 'start' => '2027-06-03T08:00:00+00:00', 'end' => '2027-06-03T09:00:00+00:00'],
                    ])),
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
     * kissj is the one untrusted boundary in the app and the contract is unverified, so
     * a 200 carrying the wrong shape has to be a provider error the module can catch —
     * not a TypeError five frames in, which is a 500 on /programy.
     */
    public function testAMalformedPayloadIsAProviderErrorAndNotACrash(): void
    {
        foreach ([json_encode([1, 2, 3]), '<html>maintenance</html>', json_encode([['name' => 'Bez id']])] as $body) {
            $mock = new MockHandler([new Response(200, [], (string) $body)]);

            try {
                $this->provider($mock)->getPrograms();
                self::fail(sprintf('no provider error for payload %s', $body));
            } catch (ProgramDataException) {
                self::assertTrue(true);
            }
        }
    }
}
