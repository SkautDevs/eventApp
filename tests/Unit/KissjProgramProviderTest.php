<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;
use App\Program\KissjProgramProvider;
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
}
