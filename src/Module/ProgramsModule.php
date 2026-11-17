<?php

declare(strict_types=1);

namespace App\Module;

use App\Auth\Authenticator;
use App\EventConfig;
use App\Http\SameOrigin;
use App\Kernel;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\RegistrationRefusedException;
use App\Program\RegistrationWindow;
use App\Program\SessionExpiredException;
use App\Push\MessageRepository;
use App\Push\NewsItems;
use App\Session;
use App\Telemetry\Collector;
use App\Program\ProgramUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Views\Twig;

/**
 * The single Program screen (/programy).
 *
 * The screen renders three views into one page and switches between them client side:
 * Přehled, a list of everything by day; Harmonogram, a Gantt-style timeline of
 * everything; and Moje, the same list of the programmes the logged-in participant is
 * registered for. All grouping happens here, in PHP — the template only walks an
 * already-shaped view model.
 */
final class ProgramsModule implements ModuleInterface
{
    /**
     * Czech weekday abbreviations for the pager label, indexed by date('N').
     * Kernel carries the full names for its dateToCzechDayName filter; the pager
     * needs the short forms, which do not exist anywhere else.
     */
    private const array SHORT_DAY_NAMES = [1 => 'po', 2 => 'út', 3 => 'st', 4 => 'čt', 5 => 'pá', 6 => 'so', 7 => 'ne'];

    /**
     * The toast title for each of kissj's refusal codes (docs/kissj-contract.md); a code
     * not listed here is REFUSED.
     */
    private const array REFUSALS = [
        'notPaid' => 'Přihlašování na programy je možné až po zaplacení.',
        'closed' => 'Přihlašování není otevřené.',
        'notFound' => 'Tenhle program už neexistuje.',
        'notPossible' => 'Na tenhle program se nepřihlašuje, máš ho automaticky.',
        'wrongRole' => 'Tenhle program není určený pro tvoji roli.',
        'alreadyRegistered' => 'Tenhle program už máš.',
        'notRegistered' => 'Tenhle program nemáš, není z čeho se odhlásit.',
        'conflict' => 'Koliduje s programem, který už máš ve stejném bloku.',
        'full' => 'Je plno.',
    ];

    /** The toast title for a refusal code REFUSALS does not know, per verb (true: register). */
    private const array REFUSED = [true => 'Přihlášení se nepodařilo.', false => 'Odhlášení se nepodařilo.'];

    /** The toast title when the provider could not be reached or answered nonsense, per verb. */
    private const array WRITE_FAILED = [
        true => 'Přihlášení se teď nedaří, zkus to prosím později.',
        false => 'Odhlášení se teď nedaří, zkus to prosím později.',
    ];

    /** The 403 page's message for a cross-site form, per verb. */
    private const array CROSS_SITE = [
        true => 'Přihlášení na program se nepodařilo. Načti stránku a zkus to znovu.',
        false => 'Odhlášení z programu se nepodařilo. Načti stránku a zkus to znovu.',
    ];

    /**
     * The refusal that, right after the same write succeeded, is only that write tapped
     * twice: the success toast still waiting in the session is the true answer.
     */
    private const array REPEATED = [true => 'alreadyRegistered', false => 'notRegistered'];

    /** The sheet's line, the toast's second line and the 403 page's message for a reader who is not paired. */
    private const string NOT_PAIRED = 'Pro přihlašování se přihlas kódem z KissJ';

    /**
     * The one way a time of day is printed in a time range — the sheet's Kdy, the toast,
     * the list rows and the timeline card's name: no leading zero on the hour ("8:00"),
     * as Czech writes it, and Domů's next-programme cards (HomeModel) the same way. The
     * hour ruler keeps "08:00", a fixed-width instrument label.
     */
    public const string CLOCK = 'G:i';

    /** Stage row for programmes that carry no location. */
    private const string NO_LOCATION_LABEL = 'Bez lokace';

    /** Width of one timeline card lane in seconds — the axis is always rounded to whole hours. */
    private const int HOUR = 3600;

    /**
     * Most calendar days one programme is drawn across. Each day's piece is clipped to
     * that day, so no record can stretch a page's axis; this bounds the other way a
     * typo'd `end` from kissj could hurt — a bar on every day of the next ten years.
     * The clip is a drawing decision only: the detail sheet still shows the true end.
     */
    private const int MAX_DRAWN_DAYS = 14;

    /** Shortest bar the grid will draw, in seconds — below this a card is unreadable. */
    private const int MIN_DRAWN_SPAN = 60;

    /**
     * Kernel::TIMEZONE: the zone the provider's naive datetimes are wall-clock times in.
     * Everything else here reads and prints them in the process's default zone, which is
     * consistent whatever that zone is; only an instant handed to the client has to name
     * the event's own.
     */
    private const string ZONE = 'Europe/Prague';

    public static function key(): string
    {
        return 'programs';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Program', 'route' => 'programs', 'icon' => 'far fa-calendar-alt', 'order' => 10];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/programy', function ($request, $response) {
            $auth = $this->get(Authenticator::class);
            $provider = $this->get(ProgramProviderInterface::class);
            // Both provider calls can fail in the same request — kissj being down is
            // exactly when they do — so the notices accumulate and the template renders
            // them as one line.
            $notices = [];
            $mine = [];
            // only a list that was actually read can say "not registered" or "nothing yet"
            $mineRead = false;
            // what a register or unregister said, on the document its redirect lands on
            $toast = Kernel::takeToast($request, $this->get(Session::class));

            $identity = $auth->identity();
            if ($identity !== null) {
                try {
                    $mine = $provider->getParticipant($identity->sessionToken)->programmes;
                    $mineRead = true;
                } catch (SessionExpiredException) {
                    // kissj no longer knows the device session: logged out, as on Domů, and
                    // this very page renders logged out, so it says why itself
                    Kernel::sessionGone($auth, $this->get(Session::class));
                    $toast = Kernel::goneToast();
                } catch (ProgramUnavailableException | ProgramDataException) {
                    // never arrived, or arrived as something that is not programme data —
                    // the reader is told the same thing either way
                    $notices[] = 'Osobní program se nepodařilo načíst.';
                }
            }

            $all = [];
            $sections = [];
            // no list at all, not even a stale one: the template then says so instead of "no programme"
            $unavailable = false;
            try {
                $all = $provider->getPrograms();
                $sections = $provider->getSections();
            } catch (ProgramUnavailableException | ProgramDataException) {
                $notices[] = 'Programy se nepodařilo načíst, zkus to prosím později.';
                $unavailable = true;
            }

            $model = ProgramsModule::buildViewModel($sections, $all, $mine, $mineRead);

            // the window decides every sheet's footer; read only when there is a sheet, and
            // a window that cannot be read leaves the footers empty rather than guessing
            $window = null;
            if ($model['details'] !== []) {
                try {
                    $window = $provider->getRegistrationWindow();
                } catch (ProgramUnavailableException | ProgramDataException) {
                    $window = null;
                }
            }

            // the organisers' messages about a programme are in its sheet, for everybody
            $messages = [];
            $now = new \DateTimeImmutable();
            foreach ($this->get(MessageRepository::class)->visible($this->get(EventConfig::class)->slug) as $message) {
                if ($message['programmeId'] !== null) {
                    $sentAt = NewsItems::sentAt($message['sentAt']);
                    $messages[$message['programmeId']][] = [
                        'when' => $sentAt === null ? $message['sentAt'] : NewsItems::relative($sentAt, $now),
                        // the instant www/shell.js redraws `when` from on the device clock
                        'at' => $sentAt?->format(\DATE_ATOM),
                        'title' => $message['title'],
                        'body' => $message['body'],
                    ];
                }
            }
            $paired = $auth->isLogged();
            foreach ($model['details'] as $i => $detail) {
                $model['details'][$i]['messages'] = $messages[(int) $detail['id']] ?? [];
                $model['details'][$i]['footer'] = $window === null && $paired
                    ? null
                    : ProgramsModule::footer($detail, $paired, $mineRead, $window ?? new RegistrationWindow(null, null), $now);
            }

            return Kernel::markToast($this->get(Twig::class)->render($response, 'programs.twig', $model + [
                'notice' => $notices === [] ? null : implode(' ', $notices),
                'programmesUnavailable' => $unavailable,
                'isLogged' => $paired,
                'mineRead' => $mineRead,
                'toast' => $toast,
            ]), $toast);
        })->setName('programs');

        // Registration from the sheet: a plain form POST, so the worker's purge-on-write and
        // the loader's "every non-GET is a navigation" keep every screen consistent. The
        // outcome waits in the session as a toast, and the redirect lands on the
        // programme's deep link, which reopens its sheet under that toast.
        $base = $app->getBasePath();
        foreach (['prihlasit' => 'program-register', 'odhlasit' => 'program-unregister'] as $path => $name) {
            $register = $path === 'prihlasit';
            $app->post('/programy/{id:[0-9]+}/' . $path, function ($request, $response, array $args) use ($base, $register) {
                return ProgramsModule::write($this, $request, $response, (int) $args['id'], $register, $base);
            })->setName($name);
        }
    }

    /**
     * One register or unregister: the provider's answer becomes the session toast, and the
     * reader goes back to the programme. Only a cross-site form or a reader who is not
     * paired gets a page of its own (403).
     */
    private static function write(
        \Psr\Container\ContainerInterface $c,
        \Psr\Http\Message\ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        bool $register,
        string $base,
    ): ResponseInterface {
        $twig = $c->get(Twig::class);
        // a cross-site form must not register the reader for anything
        if (!SameOrigin::allows($request)) {
            return self::refused($twig, $response, self::CROSS_SITE[$register], $base . '/programy', 'Zpět na program');
        }
        $auth = $c->get(Authenticator::class);
        $identity = $auth->identity();
        if ($identity === null) {
            return self::refused($twig, $response, self::NOT_PAIRED . '.', $base . '/profil', 'Přihlásit se');
        }
        $session = $c->get(Session::class);
        $provider = $c->get(ProgramProviderInterface::class);

        // the success toast of this very write, which a repeat of it must not talk over
        $tag = ($register ? 'register:' : 'unregister:') . $id;
        try {
            if ($register) {
                $provider->register($identity->sessionToken, $id);
            } else {
                $provider->unregister($identity->sessionToken, $id);
            }
            $programme = self::programme($provider, $id);
            $name = $programme === null ? null : (string) $programme['name'];
            if ($register) {
                Kernel::putToast($session, $name === null ? 'Přihlášen' : 'Přihlášen: ' . $name, $programme === null ? null : self::toastLine($programme), $tag);
            } else {
                Kernel::putToast($session, $name === null ? 'Odhlášen' : 'Odhlášen: ' . $name, 'Místo jsme uvolnili', $tag);
            }
        } catch (RegistrationRefusedException $e) {
            $title = self::REFUSALS[$e->refusal] ?? self::REFUSED[$register];
            if ($e->refusal === self::REPEATED[$register]) {
                // A double tap: the first request's success waits unsaid in the session, and
                // the second one's "you already have it" would replace it with a refusal.
                Kernel::putToastUnlessPending($session, $title, null, $tag);
            } else {
                Kernel::putToast($session, $title, null);
            }
        } catch (SessionExpiredException) {
            // the page the redirect lands on renders logged out, and the toast says why
            Kernel::sessionGone($auth, $session);
            $gone = Kernel::goneToast();
            Kernel::putToast($session, $gone['title'], $gone['line']);
        } catch (ProgramUnavailableException | ProgramDataException $e) {
            // LocalStateException included: the local source's state file could not be written
            Collector::collect($e);
            Kernel::putToast($session, self::WRITE_FAILED[$register], null);
        }

        $sectionId = (int) (self::programme($provider, $id)['section']['id'] ?? 0);

        return $response
            ->withHeader('Location', $base . '/programy#section-' . $sectionId . '-program-' . $id)
            ->withStatus(302);
    }

    /** The programme with this id from the provider's list, or null when it is not there or the list cannot be read. */
    private static function programme(ProgramProviderInterface $provider, int $id): ?array
    {
        try {
            foreach ($provider->getPrograms() as $programme) {
                if (is_array($programme) && ($programme['id'] ?? null) === $id) {
                    return $programme;
                }
            }
        } catch (ProgramUnavailableException | ProgramDataException) {
            // the toast then names no programme, and the deep link no section
        }

        return null;
    }

    private static function refused(Twig $twig, ResponseInterface $response, string $message, string $href, string $label): ResponseInterface
    {
        return $twig->render($response->withStatus(403)->withHeader('Content-Type', 'text/html; charset=utf-8'), 'error.twig', [
            'notFound' => false,
            'details' => null,
            'message' => $message,
            'back' => ['href' => $href, 'label' => $label],
        ]);
    }

    /**
     * When and where, as the register toast's second line: "Pá 21. 5. · 9:00–12:30 ·
     * Žlutý les". A programme running past midnight names its start day only; the place
     * only when there is one.
     */
    public static function toastLine(array $program): string
    {
        $start = self::ts($program['start'] ?? null);
        $end = self::ts($program['end'] ?? null);
        $day = self::SHORT_DAY_NAMES[(int) date('N', $start)];
        $line = mb_strtoupper(mb_substr($day, 0, 1)) . mb_substr($day, 1) . ' ' . date('j. n.', $start)
            . ' · ' . date(self::CLOCK, $start) . '–' . date(self::CLOCK, $end);
        $location = trim((string) ($program['location'] ?? ''));

        return $location === '' ? $line : $line . ' · ' . $location;
    }

    /**
     * What a sheet's footer offers, in the order the spec's bullets imply: not paired
     * first; then a window that is not open, by why; then, paired and open, nothing for a
     * preregistered programme, else register or unregister. Null when the participant's
     * own list could not be read: "register" would be a guess.
     *
     * @param array{preregistered: bool, registered: bool} $detail
     * @return array{state: string, line: ?string}|null
     */
    private static function footer(array $detail, bool $paired, bool $mineRead, RegistrationWindow $window, \DateTimeImmutable $now): ?array
    {
        if (!$paired) {
            return ['state' => 'unpaired', 'line' => null];
        }
        $state = $window->state($now);
        if ($state === 'before' && $window->start !== null) {
            $start = $window->start->setTimezone(new \DateTimeZone(self::ZONE));

            return ['state' => 'before', 'line' => 'Přihlašování začne ' . $start->format('j. n. Y \\v ' . self::CLOCK)];
        }
        if ($state === 'after') {
            return ['state' => 'after', 'line' => 'Přihlašování skončilo'];
        }
        if ($state !== 'open') {
            return ['state' => 'none', 'line' => 'Přihlašování zatím není otevřené'];
        }
        if ($detail['preregistered']) {
            return ['state' => 'fixed', 'line' => null];
        }
        if (!$mineRead) {
            return null;
        }

        return ['state' => $detail['registered'] ? 'unregister' : 'register', 'line' => null];
    }

    /**
     * The homepage's "next programme" card: the participant's own programmes in order,
     * flattened from Moje's days, and the index of the first one not yet over at
     * `$now` (null when everything is). Every card is rendered and the device clock picks
     * the one to show (`www/shell.js`), so a copy cached days ago is still right; the
     * server's own pick is what a reader without JS sees.
     *
     * @param list<array> $mine
     * @return array{items: list<array>, index: ?int}
     */
    public static function nextUp(array $mine, int $now): array
    {
        $mine = self::withUniqueIds(self::withParsableDates($mine));
        $items = [];
        foreach (self::buildDays($mine, array_fill_keys(array_column($mine, 'id'), true), true) as $day) {
            foreach ($day['items'] as $item) {
                $items[] = $item + ['day' => $day['label']];
            }
        }
        $index = null;
        foreach ($items as $i => $item) {
            if (strtotime($item['end']) > $now) {
                $index = $i;
                break;
            }
        }

        return ['items' => $items, 'index' => $index];
    }

    /**
     * Drops every record the screen cannot place in time. `strtotime` answers false for
     * an empty or missing date, `ts()` would turn that into 1970-01-01, and a single such
     * record puts the whole screen on a page labelled "čt 1. 1." — `activeKey` opens on
     * the first page whenever today is not one of them. It arrives without any malice:
     * KissjProgramProvider maps a missing `start` to an empty string.
     *
     * @param list<array> $programs
     * @return list<array>
     */
    private static function withParsableDates(array $programs): array
    {
        return array_values(array_filter(
            $programs,
            static fn (array $program): bool => self::parse($program['start'] ?? null) !== null
                && self::parse($program['end'] ?? null) !== null,
        ));
    }

    /**
     * Keeps the first record of every id. Two records sharing one id render two cards but
     * a single detail body, so both cards open the second one's sheet; and the morph keys
     * on the id, where a duplicate is re-created instead of matched and loses its live
     * state. The cards, the details and the morph agree only if the id is unique here.
     *
     * @param list<array> $programs
     * @return list<array>
     */
    private static function withUniqueIds(array $programs): array
    {
        $byId = [];
        foreach ($programs as $program) {
            $byId[$program['id']] ??= $program;
        }

        return array_values($byId);
    }

    /**
     * Shapes everything the template needs:
     *  - pages:  one per day that actually has programmes, every section together, each
     *            already carrying its hour ruler and its stage rows with positioned cards
     *  - overview: one per day of every programme in a listed section, for Přehled
     *  - days:   one per day of the participant's own programme, for Moje
     *  - details: modal payload per programme id, for every view and the hash deep link
     *
     * @param array<int, array> $sections from getSections(), keyed by id in display order
     * @param list<array> $all programmes from getPrograms()
     * @param list<array> $mine programmes from getProgramsForIdentity()
     * @param bool $mineRead whether the personal list was read: only then is "not
     *                       registered" true, and only then is anything dimmed
     */
    private static function buildViewModel(array $sections, array $all, array $mine, bool $mineRead): array
    {
        // everything below reads the dates and the ids as given, so both are made sound
        // once, here, rather than guarded at every use
        $all = self::withUniqueIds(self::withParsableDates($all));
        $mine = self::withUniqueIds(self::withParsableDates($mine));

        $registeredIds = [];
        foreach ($mine as $program) {
            $registeredIds[$program['id']] = true;
        }

        // programmes in a section the provider did not list are not shown
        $listed = array_values(array_filter(
            $all,
            static fn (array $program): bool => isset($sections[$program['section']['id'] ?? -1]),
        ));

        // group everything by day. A programme running over several days goes on the
        // page of each of them.
        $grouped = [];
        foreach ($listed as $program) {
            foreach (self::segments($program) as $segment) {
                $grouped[$segment['day']][] = $segment;
            }
        }
        ksort($grouped);

        $pages = [];
        $pageOfProgram = [];
        foreach ($grouped as $day => $segments) {
            $page = self::buildPage($day, $segments, $registeredIds, $mineRead);
            // the days run in order, so the first page a programme is met on is the
            // one it starts on — the page its sheet and its deep link belong to
            foreach ($segments as $segment) {
                $pageOfProgram[$segment['program']['id']] ??= $page['key'];
            }
            $pages[] = $page;
        }

        $overview = self::buildDays($listed, $registeredIds, $mineRead);
        $days = self::buildDays($mine, $registeredIds, $mineRead);

        $details = [];
        foreach (array_merge($all, $mine) as $program) {
            $details[$program['id']] = self::buildDetail($program, $sections, $pageOfProgram, $registeredIds);
        }

        return [
            'pages' => $pages,
            'activePage' => self::activeKey($pages),
            // Přehled and Moje are continuous scrolls, so each strip opens on the day at the top
            'overview' => $overview,
            'activeOverview' => $overview[0]['key'] ?? null,
            'days' => $days,
            // The personal list is one continuous scroll over the whole event, so the
            // day its strip opens on is the day at the top of that scroll — the first —
            // and not today. The timeline still opens on today, because it pages.
            'activeDay' => $days[0]['key'] ?? null,
            'details' => array_values($details),
        ];
    }

    /**
     * One timeline page: the hour ruler plus one stage row per location, each row split
     * into as many tracks as it takes for overlapping programmes not to cover each other.
     *
     * @param list<array{program: array, day: string, start: int, end: int}> $segments this day's pieces
     * @param array<int, true> $registeredIds
     */
    private static function buildPage(string $day, array $segments, array $registeredIds, bool $mineRead): array
    {
        // the axis is measured against the ends the bars are actually drawn to, so a
        // programme running on past midnight widens this page no further than the day —
        // and a bar widened to the minimum still ends inside the grid
        $starts = array_column($segments, 'start');
        $ends = array_map(self::barEnd(...), $segments);

        $axisStart = intdiv(min($starts), self::HOUR) * self::HOUR;
        $axisEnd = (int) (ceil(max($ends) / self::HOUR) * self::HOUR);
        if ($axisEnd <= $axisStart) {
            $axisEnd = $axisStart + self::HOUR;
        }

        // the last tick sits on the right edge, where its label would hang outside the grid.
        // Offsets are in hours, not percent: the grid is drawn at a fixed pixel-per-hour
        // scale (--hour-width), so every position is calc(--hour-width * offset).
        $ruler = [];
        for ($tick = $axisStart; $tick < $axisEnd; $tick += self::HOUR) {
            $ruler[] = ['label' => date('H:i', $tick), 'offset' => self::hours($tick - $axisStart)];
        }

        $byLocation = [];
        foreach ($segments as $segment) {
            $location = trim((string) ($segment['program']['location'] ?? ''));
            $byLocation[$location][] = $segment;
        }
        // stages read top to bottom in the order their first programme starts; no location goes last.
        // The earliest start is computed once per location rather than inside the comparator,
        // which walked both groups in full on every one of the O(n log n) comparisons.
        $firstStart = array_map(
            static fn (array $items): int => min(array_column($items, 'start')),
            $byLocation,
        );
        uksort($byLocation, static fn ($a, $b): int => $firstStart[$a] <=> $firstStart[$b]);

        $rows = [];
        foreach ($byLocation as $location => $items) {
            $rows[] = [
                'location' => $location === '' ? self::NO_LOCATION_LABEL : $location,
                'tracks' => self::packTracks($items, $axisStart, $registeredIds, $mineRead),
            ];
        }
        // the unlocated stage sorts by time like the rest, but it belongs at the bottom
        usort($rows, static fn (array $a, array $b): int => ($a['location'] === self::NO_LOCATION_LABEL ? 1 : 0)
            <=> ($b['location'] === self::NO_LOCATION_LABEL ? 1 : 0));

        return [
            'key' => 'page-' . date('Ymd', strtotime($day)),
            'day' => $day,
            'label' => self::dayLabel($day),
            'hours' => count($ruler),
            'ruler' => $ruler,
            // the axis as instants, so the client can place "now" on it whatever
            // time zone the phone is set to
            'axisStart' => self::instant($axisStart),
            'axisEnd' => self::instant($axisEnd),
            'rows' => $rows,
        ];
    }

    /**
     * A wall-clock timestamp (read in the default zone, like every other in here) as the
     * instant it means in the event's zone, DATE_ATOM with its offset.
     */
    private static function instant(int $timestamp): string
    {
        return (new \DateTimeImmutable(date('Y-m-d H:i:s', $timestamp), new \DateTimeZone(self::ZONE)))->format(DATE_ATOM);
    }

    /**
     * Greedy interval packing: a programme goes into the first track whose previous
     * programme has already ended, so nothing is ever drawn on top of anything else.
     *
     * @param list<array{program: array, day: string, start: int, end: int}> $items
     * @param array<int, true> $registeredIds
     * @return list<list<array>>
     */
    private static function packTracks(array $items, int $axisStart, array $registeredIds, bool $mineRead): array
    {
        usort($items, static fn (array $a, array $b): int => [$a['start'], -$a['end']] <=> [$b['start'], -$b['end']]);

        // packing works on the same end the bar is drawn to, clip and minimum width
        // included: freeing a track at the clipped end while buildCard() widens a shorter
        // programme to MIN_DRAWN_SPAN put the next card underneath that widened bar
        $trackEnds = [];
        $tracks = [];
        foreach ($items as $segment) {
            $index = 0;
            while (isset($trackEnds[$index]) && $trackEnds[$index] > $segment['start']) {
                $index++;
            }
            $trackEnds[$index] = self::barEnd($segment);
            $tracks[$index][] = self::buildCard($segment, $axisStart, $registeredIds, $mineRead);
        }
        ksort($tracks);

        return array_values($tracks);
    }

    /**
     * One card: a programme's piece of one day. Every piece of a programme carries the
     * same id, label and state, so each opens the same sheet and says the same thing.
     *
     * @param array{program: array, day: string, start: int, end: int} $segment
     * @param array<int, true> $registeredIds
     */
    private static function buildCard(array $segment, int $axisStart, array $registeredIds, bool $mineRead): array
    {
        $program = $segment['program'];
        $start = $segment['start'];
        $end = self::barEnd($segment);
        $registered = isset($registeredIds[$program['id']]);
        $preregistered = self::preregistered($program);

        return [
            'id' => $program['id'],
            'name' => $program['name'],
            // the label keeps the true times, like the sheet — only the bar is clipped
            'time' => self::timeRange($program),
            // both in hours from the axis start; the template turns them into pixels
            'offset' => self::hours($start - $axisStart),
            'span' => self::hours($end - $start),
            'registered' => $registered,
            // with the personal list read, the programmes that are not yours step back so
            // yours stand out — never a preregistered one, which kissj leaves out of the
            // personal list and which is, as far as eventApp can tell, everybody's
            'dimmed' => $mineRead && !$registered && !$preregistered,
        ];
    }

    /** kissj's isPreregistered: fixed for its roles, never in a participant's own list. */
    private static function preregistered(array $program): bool
    {
        return ($program['preregistered'] ?? false) === true;
    }

    /**
     * Přehled and Moje group by day, like the timeline, but do not page: every day is
     * rendered and the reader scrolls through all of them — a day with nothing on it
     * simply does not exist in the list. One shape for both; the template decides what
     * each view shows of it (only Přehled dims).
     *
     * @param list<array> $programs
     * @param array<int, true> $registeredIds the participant's programme ids
     * @param bool $mineRead whether the personal list was read: only then is anything dimmed
     */
    private static function buildDays(array $programs, array $registeredIds, bool $mineRead): array
    {
        $byDay = [];
        foreach ($programs as $program) {
            $byDay[self::dayOf($program)][] = $program;
        }
        ksort($byDay);

        $days = [];
        foreach ($byDay as $day => $dayPrograms) {
            usort($dayPrograms, static fn (array $a, array $b): int => self::ts($a['start']) <=> self::ts($b['start']));
            $days[] = [
                'key' => 'day-' . date('Ymd', strtotime($day)),
                'day' => $day,
                'label' => self::dayLabel($day),
                'items' => array_map(static function (array $p) use ($registeredIds, $mineRead): array {
                    $start = self::ts($p['start']);
                    $end = self::ts($p['end']);
                    $registered = isset($registeredIds[$p['id']]);
                    $location = trim((string) ($p['location'] ?? ''));

                    return [
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'time' => date(self::CLOCK, $start),
                        // a programme running into another day names the day it ends on,
                        // as every other label of a multi-day run does
                        'until' => 'do ' . (count(self::segments($p)) > 1 ? self::dayLabel(date('Y-m-d', $end)) . ' ' : '') . date(self::CLOCK, $end),
                        'range' => date(self::CLOCK, $start) . '–' . date(self::CLOCK, $end),
                        'start' => self::instant($start),
                        'end' => self::instant($end),
                        'location' => $location === '' ? null : $location,
                        'perex' => $p['perex'] ?? null,
                        'registered' => $registered,
                        // a preregistered programme is never dimmed: see buildCard()
                        'dimmed' => $mineRead && !$registered && !self::preregistered($p),
                    ];
                }, $dayPrograms),
            ];
        }

        return $days;
    }

    /**
     * @param array<int, array> $sections
     * @param array<int, string> $pageOfProgram
     * @param array<int, true> $registeredIds
     */
    private static function buildDetail(array $program, array $sections, array $pageOfProgram, array $registeredIds): array
    {
        $section = $sections[$program['section']['id'] ?? -1] ?? null;

        return [
            'id' => $program['id'],
            'name' => $program['name'],
            'when' => self::whenLabel($program),
            'location' => $program['location'] ?? null,
            'perex' => $program['perex'] ?? null,
            'lector' => $program['lector'] ?? null,
            'tools' => $program['tools'] ?? null,
            'section' => $section === null ? null : self::sectionTitle($section),
            'page' => $pageOfProgram[$program['id']] ?? null,
            'registered' => isset($registeredIds[$program['id']]),
            'preregistered' => self::preregistered($program),
        ];
    }

    /**
     * The screen opens on today if the event is running, otherwise on its first page.
     *
     * @param list<array{key: string, day: string}> $pages
     */
    private static function activeKey(array $pages): ?string
    {
        $today = date('Y-m-d');
        foreach ($pages as $page) {
            if ($page['day'] === $today) {
                return $page['key'];
            }
        }

        return $pages[0]['key'] ?? null;
    }

    private static function sectionTitle(array $section): string
    {
        return isset($section['subTitle']) ? $section['title'] . ' – ' . $section['subTitle'] : $section['title'];
    }

    /** e.g. "čt 30. 5." */
    private static function dayLabel(string $day): string
    {
        $date = new \DateTimeImmutable($day);

        return self::SHORT_DAY_NAMES[(int) $date->format('N')] . ' ' . $date->format('j. n.');
    }

    /**
     * e.g. "8:00–12:00"; a programme that runs into another day names both days, the
     * way the sheet does. Ending at midnight is ending the evening it started on.
     */
    private static function timeRange(array $program): string
    {
        $segments = self::segments($program);
        if (count($segments) > 1) {
            return self::whenLabel($program);
        }

        return date(self::CLOCK, self::ts($program['start'])) . '–' . date(self::CLOCK, self::ts($program['end']));
    }

    /**
     * e.g. "čt 30. 5. 8:00–12:00", or with both days when the programme runs past midnight:
     * "čt 30. 5. 22:00 – pá 31. 5. 1:30" — spaced there, because the dash joins two dates.
     */
    private static function whenLabel(array $program): string
    {
        $start = self::ts($program['start']);
        $end = self::ts($program['end']);
        $startDay = self::dayLabel(date('Y-m-d', $start));
        $endDay = self::dayLabel(date('Y-m-d', $end));

        return $startDay === $endDay
            ? $startDay . ' ' . date(self::CLOCK, $start) . '–' . date(self::CLOCK, $end)
            : $startDay . ' ' . date(self::CLOCK, $start) . ' – ' . $endDay . ' ' . date(self::CLOCK, $end);
    }

    private static function dayOf(array $program): string
    {
        return date('Y-m-d', self::ts($program['start']));
    }

    private static function ts(mixed $datetime): int
    {
        return self::parse($datetime) ?? 0;
    }

    /**
     * The timestamp of a `{'date': ...}` value, or null when there is none to read —
     * a null, a missing key, an empty string or anything strtotime() rejects.
     * withParsableDates() is what keeps such a record off the screen; this is also
     * why ts() takes mixed, so a hand-edited fixture is a dropped row and not a
     * TypeError five frames further in.
     */
    private static function parse(mixed $datetime): ?int
    {
        $date = is_array($datetime) ? ($datetime['date'] ?? null) : null;
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * A programme cut into one piece per calendar day it overlaps, each clipped to its
     * day: from the later of its start and the day's midnight to the earlier of its end
     * and the next midnight. The day it starts on always has a piece, even a record of
     * no length; any later day only if the programme is still running into it, so an
     * end at exactly midnight leaves no zero-width card on the next day. Only the bars
     * are cut — buildDetail reads $program['end'] directly, so the sheet keeps the true time.
     *
     * @return list<array{program: array, day: string, start: int, end: int}>
     */
    private static function segments(array $program): array
    {
        $start = self::ts($program['start']);
        $end = max(self::ts($program['end']), $start);
        $day = date('Y-m-d', $start);

        $segments = [];
        do {
            // by the calendar rather than by 86400 seconds, which a DST change would break
            $dayStart = (int) strtotime($day);
            $nextDay = date('Y-m-d', (int) strtotime($day . ' +1 day'));
            $dayEnd = (int) strtotime($nextDay);
            $segments[] = ['program' => $program, 'day' => $day, 'start' => max($start, $dayStart), 'end' => min($end, $dayEnd)];
            $day = $nextDay;
        } while ($end > $dayEnd && count($segments) < self::MAX_DRAWN_DAYS);

        return $segments;
    }

    /**
     * The end of the bar as it is actually drawn: the piece's clipped end, or a minute
     * past its start for a piece too short to see. The packer frees a track at this same
     * value, or a widened bar would be drawn over the card that took the slot after it.
     *
     * @param array{start: int, end: int} $segment
     */
    private static function barEnd(array $segment): int
    {
        return max($segment['end'], $segment['start'] + self::MIN_DRAWN_SPAN);
    }

    /** A duration in hours, the unit the timeline's --hour-width scale is expressed in. */
    private static function hours(int $seconds): float
    {
        return round($seconds / self::HOUR, 4);
    }
}
