<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Program\KissjProgramProvider;
use App\Program\ProgramProviderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Slim\App;
use Tests\KorboResponses;

/**
 * The Program screen over realistic kissj data: the real KissjProgramProvider, fed the
 * Korbo responses (16–20 Sep 2026) through a MockHandler, inside the real app. The
 * provider is what matters here, so the event is the minimal `programs` fixture event.
 */
final class KorboProgramsTest extends AppTestCase
{
    private const array TIE_IDS = [3, 5, 10, 26, 31, 36, 45, 50];

    /**
     * The queue is the exact sequence of kissj calls the requests make, so a call the
     * screen was not expected to make runs the queue dry and fails the test.
     */
    private function app(string ...$responses): App
    {
        $provider = new KissjProgramProvider(
            http: new Client([
                'handler' => HandlerStack::create(new MockHandler(array_map(KorboResponses::response(...), $responses))),
                'base_uri' => 'https://kissj.example/',
            ]),
            apiKey: 'secret-key',
        );

        return $this->createApp('programs', [
            ProgramProviderInterface::class => $provider,
        ], fixtureEvent: true);
    }

    private function screen(): string
    {
        $response = $this->request($this->app(KorboResponses::LIST), 'GET', '/programy');
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** Logging in asks kissj once, and the screen asks again before it fetches the list. */
    private function loggedInScreen(): string
    {
        $app = $this->app(KorboResponses::TIE, KorboResponses::TIE, KorboResponses::LIST);
        self::assertSame(302, $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'KORBO1'])->getStatusCode());

        $response = $this->request($app, 'GET', '/programy');
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * The class list after `tl-card` of every timeline card, keyed by programme id. A
     * programme running over several days has a card on each of their pages, and every
     * one of them must say the same thing about it, so they collapse to one entry here.
     *
     * @return array<int, string>
     */
    private static function cards(string $html): array
    {
        $cards = [];
        foreach (self::cardCounts($html, $classesById) as $id => $count) {
            self::assertCount(1, array_unique($classesById[$id]), sprintf('the cards of programme %d disagree', $id));
            $cards[$id] = $classesById[$id][0];
        }

        return $cards;
    }

    /**
     * @param-out array<int, list<string>> $classesById
     * @return array<int, int> how many timeline cards each programme has, keyed by id
     */
    private static function cardCounts(string $html, ?array &$classesById = null): array
    {
        preg_match_all('/<button type="button" class="tl-card([^"]*)" data-key="(\d+)"/', $html, $matches, PREG_SET_ORDER);
        $classesById = [];
        foreach ($matches as [, $classes, $id]) {
            $classesById[(int) $id][] = $classes;
        }
        ksort($classesById);

        return array_map('count', $classesById);
    }

    /** @return string the markup of one timeline page, by its key */
    private static function page(string $html, string $key): string
    {
        self::assertSame(1, preg_match('/data-pg-panel="timeline" data-key="' . $key . '".*?<\/section>/s', $html, $match), "no page {$key}");

        return $match[0];
    }

    public function testTheScreenRenders(): void
    {
        $html = $this->screen();

        self::assertStringNotContainsString('Programy se nepodařilo načíst', $html);
        self::assertStringContainsString('data-pg-panel="timeline"', $html);
    }

    /**
     * A programme lands on the day page of every day it runs, so all of them
     * have one card except Hennování, which runs three days and has three; and every
     * programme gets exactly one detail sheet, which is what each of its cards and the
     * deep link open. All six sections are listed, so all 59 programmes have both.
     */
    public function testEveryProgrammeHasACardADayAndOneDetail(): void
    {
        $html = $this->screen();

        self::assertSame(array_replace(array_fill(1, 59, 1), [2 => 3]), self::cardCounts($html));

        preg_match_all('/data-pg-detail="(\d+)"/', $html, $details);
        $details = array_map('intval', $details[1]);
        sort($details);
        self::assertSame(range(1, 59), $details);
    }

    /**
     * The timeline pages by day, one page per event day named by the day alone, and
     * every detail sheet names its section.
     */
    public function testEverySectionAppearsOnItsDetailSheets(): void
    {
        $html = $this->screen();
        $titles = [1 => 'Pohybová', 2 => 'Tvořivá', 3 => 'Kulturní', 4 => 'Přednáška', 5 => 'Debata', 6 => 'Jiné'];

        preg_match_all('/data-pg-page="(page-\d{8})" data-pg-kind="timeline" aria-current="(?:true|false)" data-morph-keep="aria-current">([^<]*)<\/button>/u', $html, $pages);
        self::assertSame(['page-20260916', 'page-20260917', 'page-20260918', 'page-20260919', 'page-20260920'], $pages[1]);
        self::assertSame(['st 16. 9.', 'čt 17. 9.', 'pá 18. 9.', 'so 19. 9.', 'ne 20. 9.'], $pages[2]);

        // every detail sheet names its section, as many times as the section has programmes
        $expected = array_count_values(array_map(
            static fn (array $p): string => $titles[$p['sectionId']],
            KorboResponses::decoded(KorboResponses::LIST)['programmes'],
        ));
        foreach ($titles as $title) {
            self::assertSame($expected[$title], substr_count($html, '<p class="sheet-section">' . $title . '</p>'), $title);
        }
    }

    /**
     * A description is plain text (docs/kissj-contract.md). Programme 53's carries a
     * literal `->` and a literal `<…>` placeholder; Twig escapes each exactly once, and
     * nothing in it is rendered as markup.
     */
    public function testAPlainTextDescriptionIsEscapedExactlyOnce(): void
    {
        $html = $this->screen();

        self::assertStringContainsString('Chceš se poradit? -&gt;piš', $html);
        self::assertStringNotContainsString('&amp;gt;', $html, 'double-escaped');
        self::assertStringNotContainsString('->piš', $html, 'the text reached the page unescaped');
        self::assertStringContainsString("\n- 8 dní na hřebeni rumunské Rodnei\n", $html);
        // the <…> placeholder in the same text stays text
        self::assertStringContainsString('pošli na &lt;[odstraněno]&gt;.', $html);
        self::assertStringNotContainsString('<[odstraněno]>', $html);
    }

    /**
     * Line breaks in a description are meaningful, and the perex keeps them: the text
     * reaches the sheet and the list with its `\n`s intact and nothing added round it,
     * which `white-space: pre-line` then draws as the lines they are.
     */
    public function testADescriptionKeepsItsLineBreaks(): void
    {
        $html = $this->loggedInScreen();

        // programme 50, Pevnost Boyard, is on the TIE participant's list
        $text = "Zahrej si originální deskovku známé francouzské kultovní televizní gameshow. U této hry nebudeš jen sedět. Zažij netradiční úkoly za zvuků originální hudby. Zvítězí tvůj tým a dotkneš se legendárního pokladu?\n\nHra je pro 2-4 týmy a jeden tým může tvořit 2-6 hráčů.\n\nSraz u nástěnky.";
        self::assertStringContainsString('<p class="sheet-perex">' . $text . '</p>', $html);
        self::assertStringContainsString('<p class="pl-perex">' . $text . '</p>', $html);
        // what used to be a Markdown hard break (two trailing spaces) is a plain line break
        self::assertStringContainsString("<p class=\"sheet-perex\">Máme frisbee, máme louku!\nUděláme dvě čáry!\nZahrajeme přátelskou hru frisbee!\n(kdo nezná, rychle se naučí)</p>", $html);

        // and every element that renders a perex draws those breaks as lines
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/www/style.css');
        preg_match_all('/<(\w+) class="([\w-]*perex[\w-]*)"/', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/programs.twig'), $perexes);
        // the second sheet-perex is an organiser's message in the sheet, plain text like the perex
        self::assertSame(['pl-perex', 'sheet-perex', 'sheet-perex'], $perexes[2]);
        foreach ($perexes[2] as $class) {
            self::assertMatchesRegularExpression('/\n\.' . $class . ' \{[^}]*white-space: pre-line;/', $css, $class);
        }
    }

    public function testATieParticipantSeesTheirProgrammeMarked(): void
    {
        $html = $this->loggedInScreen();
        self::assertStringContainsString('TIE KORBO1', $html);

        self::assertCount(59, self::cards($html));
        foreach (self::cards($html) as $id => $classes) {
            if (in_array($id, self::TIE_IDS, true)) {
                self::assertStringContainsString('is-registered', $classes, "programme {$id}");
                self::assertStringNotContainsString('is-dimmed', $classes, "programme {$id}");
            } else {
                self::assertStringContainsString('is-dimmed', $classes, "programme {$id}");
                self::assertStringNotContainsString('is-registered', $classes, "programme {$id}");
            }
        }
        self::assertSame(count(self::TIE_IDS), substr_count($html, '<p class="sheet-mine">'));
    }

    public function testMujProgramListsExactlyTheParticipantsProgrammes(): void
    {
        $html = $this->loggedInScreen();

        preg_match_all('/<article class="pl-item" data-key="(\d+)"/', $html, $items);
        self::assertSame(self::TIE_IDS, array_map('intval', $items[1]));
        // they span four days, first to last
        preg_match_all('/<section class="pl-day" data-key="(day-\d{8})"/', $html, $days);
        self::assertSame(['day-20260916', 'day-20260917', 'day-20260918', 'day-20260919'], $days[1]);
    }

    /**
     * 23:45 → 00:00 belongs to the evening it starts on, and its bar ends at the axis end.
     * The end at midnight does not make it a two-day programme: it has no card on the
     * next day's page, and its label names no day.
     */
    public function testAProgrammeEndingAtMidnightStaysOnItsOwnEvening(): void
    {
        $html = $this->screen();
        $page = self::page($html, 'page-20260916');

        self::assertStringContainsString('data-key="8" style="left: calc(var(--hour-width) * 23.75); width: calc(var(--hour-width) * 0.25)"', $page);
        self::assertStringContainsString('aria-label="Večerka pro noční sovy, 23:45 – 00:00"', $page);
        self::assertStringNotContainsString('data-key="8"', self::page($html, 'page-20260917'));
        foreach ([8, 24, 40, 57] as $id) {
            self::assertSame(1, self::cardCounts($html)[$id], "programme {$id}");
        }
    }

    public function testAnAllDayEntryFillsItsDay(): void
    {
        $page = self::page($this->screen(), 'page-20260916');

        self::assertSame(24, substr_count($page, 'class="tl-tick"'));
        self::assertStringContainsString('data-key="1" style="left: calc(var(--hour-width) * 0); width: calc(var(--hour-width) * 23.9833)"', $page);
    }

    /**
     * Hennování runs from 16.9 00:00 to 18.9 23:59, so it is on the Tvořivá page of all
     * three days, each bar clipped to its own day: the whole of 16.9 and 17.9, and 18.9
     * up to 23:59. Every card names the whole run, and the one sheet — which the deep
     * link opens — belongs to the day it starts on.
     */
    public function testAMultiDayProgrammeIsOnEveryDayItRuns(): void
    {
        $html = $this->screen();
        $label = 'aria-label="Hennování a zaplétání copánků, st 16. 9. 00:00 – pá 18. 9. 23:59"';

        foreach ([
            'page-20260916' => 24,
            'page-20260917' => 24,
            'page-20260918' => 23.9833,
        ] as $key => $span) {
            $page = self::page($html, $key);
            self::assertStringContainsString('data-key="2" style="left: calc(var(--hour-width) * 0); width: calc(var(--hour-width) * ' . $span . ')"', $page, $key);
            self::assertStringContainsString($label, $page, $key);
            // a bar filling the day makes that day's axis the whole day, and no more
            self::assertSame(24, substr_count($page, 'class="tl-tick"'), $key);
        }
        self::assertStringNotContainsString('data-key="2"', self::page($html, 'page-20260919'));

        self::assertSame(1, substr_count($html, 'data-pg-detail="2"'));
        self::assertStringContainsString('data-key="2" data-pg-detail="2" data-page="page-20260916"', $html);
        self::assertStringContainsString('<p class="sheet-when">st 16. 9. 00:00 – pá 18. 9. 23:59</p>', $html);
    }

    /**
     * The morph matches an element on its `id`, then its `data-key`, among its siblings,
     * so a programme drawn on three pages must not put three of anything into one parent;
     * and an `id` must be unique across the whole document, or the deep link, the tab
     * panels and the label references stop meaning one thing.
     */
    public function testIdsAndKeysAreUnique(): void
    {
        foreach (['logged out' => $this->screen(), 'logged in' => $this->loggedInScreen()] as $state => $html) {
            $dom = new \DOMDocument();
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));

            $ids = [];
            foreach ((new \DOMXPath($dom))->query('//*[@id]') as $element) {
                $ids[] = $element->getAttribute('id');
            }
            self::assertNotEmpty($ids);
            self::assertSame([], array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1)), "duplicate id, {$state}");

            $keyed = 0;
            foreach ((new \DOMXPath($dom))->query('//*[*[@data-key]]') as $parent) {
                $keys = [];
                foreach ($parent->childNodes as $child) {
                    if ($child instanceof \DOMElement) {
                        $key = $child->getAttribute('id') ?: $child->getAttribute('data-key');
                        if ($key !== '') {
                            $keys[] = $key;
                        }
                    }
                }
                $keyed += count($keys);
                self::assertSame($keys, array_values(array_unique($keys)), "duplicate sibling key under <{$parent->nodeName} class=\"{$parent->getAttribute('class')}\">, {$state}");
            }
            // three cards of programme 2 and one of everything else, 59 sheets, the pages
            self::assertGreaterThan(61 + 59, $keyed);
        }
    }

    public function testAProgrammeSheetShowsItsMessagesToEveryone(): void
    {
        $messages = new \App\Push\MessageRepository(self::memoryDb());
        $id = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/events/korbo26/fixtures/registered.json'), true)['tie:KORBO1'][0];
        $messages->add('korbo26', $id, 'Program', 'Přesun na 15:00', 'Kvůli dešti', 'Lung', 0, 0, 0);

        $html = (string) $this->request($this->createApp('korbo26', overrides: [\App\Push\MessageRepository::class => $messages]), 'GET', '/programy')->getBody();

        self::assertStringContainsString('Přesun na 15:00', $html);
        self::assertStringContainsString('Oznámení', $html);
    }

    /** A registered card says so to a screen reader; the fill alone is pixels. */
    public function testARegisteredCardSaysSoInItsLabel(): void
    {
        $html = $this->loggedInScreen();
        preg_match_all('/<button type="button" class="tl-card([^"]*)" data-key="(\d+)"[^>]*aria-label="([^"]*)"/', $html, $cards, PREG_SET_ORDER);
        self::assertNotEmpty($cards);

        $registered = 0;
        foreach ($cards as [, $classes, $id, $label]) {
            if (str_contains($classes, 'is-registered')) {
                $registered++;
                self::assertStringEndsWith(', přihlášeno', $label, "programme {$id}");
            } else {
                self::assertStringEndsNotWith(', přihlášeno', $label, "programme {$id}");
            }
        }
        self::assertGreaterThan(0, $registered);

        // the sheet's own line for a registered programme, in the ty register
        self::assertStringContainsString('Tvůj program', $html);
        self::assertStringNotContainsString('Váš program', $html);
    }
}
