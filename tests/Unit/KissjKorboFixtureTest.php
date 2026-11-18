<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Program\KissjProgramProvider;
use App\Program\Programme;
use App\Program\Section;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Tests\KorboResponses;

/**
 * KissjProgramProvider against realistic kissj responses, built from a real camp
 * export (Korbo, 16–20 Sep 2026). The hand-written records in KissjProgramProviderTest
 * each test one rule; these test that the rules hold on data nobody wrote to fit them:
 * empty places, all-day entries, a three-day programme and one that ends at midnight.
 */
final class KissjKorboFixtureTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private function provider(string ...$files): KissjProgramProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler(array_map(KorboResponses::response(...), $files)));
        $stack->push(Middleware::history($this->history));

        return new KissjProgramProvider(
            http: new Client(['handler' => $stack, 'base_uri' => 'https://kissj.example/']),
            apiKey: 'secret-key',
        );
    }

    /** @return array<int, array> the normalised programmes as their stored rows (Programme::toArray()), keyed by id */
    private function programmesById(): array
    {
        $byId = [];
        foreach ($this->provider(KorboResponses::LIST)->getPrograms() as $program) {
            $byId[$program->id] = $program->toArray();
        }

        return $byId;
    }

    public function testSectionsArriveInKissjsOrder(): void
    {
        $sections = array_map(static fn(Section $section): array => $section->toArray(), $this->provider(KorboResponses::LIST)->getSections());

        self::assertSame([1, 2, 3, 4, 5, 6], array_keys($sections));
        self::assertSame(
            ['Pohybová', 'Tvořivá', 'Kulturní', 'Přednáška', 'Debata', 'Jiné'],
            array_column($sections, 'title'),
        );
        // kissj sends nulls for every presentation field of a real section
        foreach ($sections as $section) {
            self::assertNull($section['subTitle']);
        }
    }

    public function testEveryProgrammeIsMappedAndTheListIsFetchedOnce(): void
    {
        $provider = $this->provider(KorboResponses::LIST);

        $programmes = $provider->getPrograms();
        $provider->getSections();

        self::assertCount(59, $programmes);
        self::assertSame(range(1, 59), array_map(static fn(Programme $programme): int => $programme->id, $programmes));
        self::assertCount(1, $this->history, 'programmes and sections come from one response');
    }

    /** 21 programmes carry `place: ""`; the screen tests for null, so that is what they become. */
    public function testAnEmptyPlaceBecomesNoLocation(): void
    {
        $programmes = $this->programmesById();

        $withoutPlace = array_keys(array_filter($programmes, static fn(array $p): bool => $p['location'] === null));
        self::assertCount(21, $withoutPlace);
        self::assertContains(2, $withoutPlace); // Hennování a zaplétání copánků
        self::assertNotContains('', array_column($programmes, 'location'));
        self::assertSame('Volejbalové hřiště', $programmes[1]['location']);
    }

    public function testAnAllDayEntryKeepsItsLocalTimes(): void
    {
        $volejbal = $this->programmesById()[1];

        self::assertSame('2026-09-16 00:00:00', $volejbal['start']['date']);
        self::assertSame('2026-09-16 23:59:00', $volejbal['end']['date']);
    }

    public function testAMultiDayProgrammeKeepsBothOfItsDays(): void
    {
        $hennovani = $this->programmesById()[2];

        self::assertSame('Hennování a zaplétání copánků', $hennovani['name']);
        self::assertSame('2026-09-16 00:00:00', $hennovani['start']['date']);
        self::assertSame('2026-09-18 23:59:00', $hennovani['end']['date']);
    }

    /** 23:45 → 00:00 ends on the next calendar day, not at the start of its own. */
    public function testAProgrammeEndingAtMidnightEndsOnTheNextDay(): void
    {
        $programmes = $this->programmesById();

        foreach ([8 => '16', 24 => '17', 40 => '18', 57 => '19'] as $id => $day) {
            self::assertSame('Večerka pro noční sovy', $programmes[$id]['name']);
            self::assertSame("2026-09-{$day} 23:45:00", $programmes[$id]['start']['date']);
            self::assertSame(sprintf('2026-09-%02d 00:00:00', (int) $day + 1), $programmes[$id]['end']['date']);
        }
    }

    /**
     * `isPreregistered` and `targetRoles` are in every record, and three records carry
     * `isPreregistered: true`. The first becomes `preregistered`; `targetRoles` is not in
     * the internal shape, so it does not reach the screen.
     */
    public function testOnlyTheContractsFieldsReachTheInternalShape(): void
    {
        $raw = KorboResponses::decoded(KorboResponses::LIST)['programmes'];
        self::assertCount(3, array_filter($raw, static fn(array $p): bool => $p['isPreregistered'] === true));

        $preregistered = [];
        foreach ($this->programmesById() as $id => $program) {
            self::assertSame(
                ['id', 'name', 'section', 'start', 'end', 'lector', 'location', 'perex', 'tools', 'preregistered'],
                array_keys($program),
            );
            self::assertSame(['id'], array_keys($program['section']));
            if ($program['preregistered']) {
                $preregistered[] = $id;
            }
        }
        self::assertSame([3, 36, 51], $preregistered);
    }

    public function testTheRegistrationWindowIsTheFixtureEventsOpenOne(): void
    {
        $window = $this->provider(KorboResponses::LIST)->getRegistrationWindow();

        self::assertSame('2020-01-01T00:00:00+01:00', $window->start?->format(\DATE_ATOM));
        self::assertSame('2099-01-01T00:00:00+01:00', $window->end?->format(\DATE_ATOM));
    }

    /** Text passes through untouched — escaping is the template's job, done once, there. */
    public function testTextIsPassedThroughVerbatim(): void
    {
        $programmes = $this->programmesById();

        self::assertSame('Postavme si Korbo  - v tuto dobu prosím neplánuj jiné aktivity.', $programmes[3]['name']);
        self::assertStringContainsString('->piš', (string) $programmes[53]['perex']);
        self::assertStringContainsString("\n- 8 dní na hřebeni rumunské Rodnei\n", (string) $programmes[53]['perex']);
    }

    /**
     * The fixture is what kissj would send, so it follows the contract: a description is
     * plain text — no HTML entities, no Markdown — with its line breaks kept. And the
     * participant responses carry the very same records as the list, byte for byte.
     */
    public function testTheFixtureFollowsTheContract(): void
    {
        $list = [];
        foreach (KorboResponses::decoded(KorboResponses::LIST)['programmes'] as $programme) {
            $list[$programme['id']] = $programme;
            foreach (['&gt;', '&lt;', '&amp;', '&quot;', '&#', '\\-', '**', '__', "  \n"] as $markup) {
                self::assertStringNotContainsString($markup, $programme['description'], "programme {$programme['id']}");
            }
        }
        self::assertStringContainsString("\n", $list[35]['description']);

        $file = KorboResponses::PARTICIPANT;
        $body = KorboResponses::body($file);
        foreach (KorboResponses::decoded($file)['programmes'] as $programme) {
            self::assertSame($list[$programme['id']], $programme, "{$file}, programme {$programme['id']}");
            self::assertStringContainsString(self::source($list[$programme['id']]), $body, "{$file}, programme {$programme['id']}");
        }
    }

    /** @return string one programme record exactly as the list file spells it */
    private static function source(array $programme): string
    {
        $body = KorboResponses::body(KorboResponses::LIST);
        // past the sections, whose ids are small integers too
        $start = strpos($body, '"id": ' . $programme['id'] . ",\n", (int) strpos($body, '"programmes"'));
        self::assertNotFalse($start);
        $open = strrpos(substr($body, 0, $start), '{');
        $close = strpos($body, '}', $start);

        return substr($body, $open, $close - $open + 1);
    }

    /**
     * Six, none of them preregistered: kissj's session read lists real registrations only,
     * and a preregistered programme (3, 36 and 51 here) holds none.
     */
    public function testTheFixtureParticipantGetsTheirSixProgrammes(): void
    {
        $provider = $this->provider(KorboResponses::PARTICIPANT);

        $participant = $provider->getParticipant('stub-session-token');

        self::assertSame(1, $participant->id);
        self::assertSame('Testovací Korbák', $participant->nickname);
        self::assertSame([5, 10, 26, 31, 45, 50], array_column($participant->programmes, 'id'));
        self::assertSame([], array_filter(array_column($participant->programmes, 'preregistered')));
        $request = $this->history[0]['request'];
        self::assertSame('/v3/programme/participant/session', $request->getUri()->getPath());
        self::assertSame('stub-session-token', $request->getHeaderLine('X-Kissj-Device-Session'));
    }
}
