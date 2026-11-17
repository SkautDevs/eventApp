<?php

declare(strict_types=1);

namespace App\Program;

/**
 * The `local` programme source: an event's own fixture files under events/<slug>/fixtures/,
 * answering as kissj would, with no network. Dev and every test read it.
 *
 * - programs.json and sections.json are the programme list; registration.json is the
 *   registration window (`{"start": DATE_ATOM|null, "end": …}`, no file: no window).
 * - registered.json maps a participant id to their programme ids (`{"1": [3, 5]}`) and
 *   participants.json an id to a nickname (no file: no nicknames). Every event's fixture
 *   participant is id 1, and an event without registered.json has participant 1 with
 *   nothing registered.
 * - A pairing is approved on the first poll, with a fresh token each time:
 *   `stub-<participant id>-<16 hex>`. The token is all the stub needs to know who asks.
 * - Registrations made in the app and revoked tokens are kept in
 *   `<stateDir>/<slug>.json` (`{"registrations": {"1": [1, 2]}, "revoked": [...]}`), so they
 *   survive the request. Every write locks the file; a file that is not valid JSON, as a
 *   reader may catch it half-written, counts as empty. A state file that cannot be written
 *   is a LocalStateException, which callers treat as an outage.
 * - As in kissj, a preregistered programme is in no participant's list: it belongs to a
 *   role, which the app does not know.
 */
final class StubProgramProvider implements ProgramProviderInterface
{
    private const string USER_CODE = '483291';

    private const string VERIFICATION_URL = 'https://kissj.example/pairing';

    private const string TOKEN_PATTERN = '/^stub-(\d+)-[0-9a-f]{16}$/';

    /** @var array<string, array> decoded fixtures, kept for the life of the request */
    private array $decoded = [];

    /** @var list<array>|null */
    private ?array $programs = null;

    public function __construct(
        private readonly string $fixturesDir,
        private readonly string $stateDir,
    ) {
    }

    public function getPrograms(): array
    {
        return $this->programs ??= array_map(
            static fn (mixed $program): mixed => is_array($program)
                ? array_merge($program, ['preregistered' => ($program['preregistered'] ?? false) === true])
                : $program,
            array_values($this->readJson('programs.json')),
        );
    }

    /**
     * fixtures/sections.json is kissj's own `sections` list, so it goes through the same
     * mapping — and a fixture that would fail against kissj fails here too.
     */
    public function getSections(): array
    {
        return Sections::fromKissj($this->readJson('sections.json'));
    }

    public function getRegistrationWindow(): RegistrationWindow
    {
        $window = $this->readJson('registration.json');

        return new RegistrationWindow(self::instant($window['start'] ?? null), self::instant($window['end'] ?? null));
    }

    public function getParticipant(string $sessionToken): Participant
    {
        $id = $this->participantId($sessionToken);
        $nickname = $this->readJson('participants.json')[(string) $id] ?? null;
        $ids = $this->registrations($this->readState())[$id] ?? [];

        return new Participant(
            $id,
            is_string($nickname) ? $nickname : null,
            array_values(array_filter(
                $this->getPrograms(),
                static fn (mixed $program): bool => is_array($program) && in_array($program['id'] ?? null, $ids, true),
            )),
        );
    }

    public function getParticipantIdsForProgramme(int $programmeId): array
    {
        $ids = [];
        foreach ($this->registrations($this->readState()) as $participantId => $programmeIds) {
            if (in_array($programmeId, $programmeIds, true)) {
                $ids[] = $participantId;
            }
        }

        return $ids;
    }

    public function startPairing(string $deviceId): Pairing
    {
        return new Pairing(
            substr(hash('sha256', 'pairing:' . $deviceId), 0, 32),
            self::USER_CODE,
            self::VERIFICATION_URL,
            new \DateTimeImmutable('+1 hour'),
            5,
        );
    }

    /**
     * Approved at once, as the fixture participant. A fresh token on every approval, so a
     * token revoked by a logout never stands in the way of the next pairing.
     */
    public function pollPairing(string $deviceCode): PairingPoll
    {
        if (preg_match('/^[0-9a-f]{32}$/', $deviceCode) !== 1) {
            return new PairingPoll(PairingStatus::Expired);
        }
        $registered = $this->readJson('registered.json');
        $id = $registered === [] ? 1 : (int) array_key_first($registered);

        return new PairingPoll(
            PairingStatus::Approved,
            sprintf('stub-%d-%s', $id, bin2hex(random_bytes(8))),
            new \DateTimeImmutable('+6 months'),
        );
    }

    public function register(string $sessionToken, int $programmeId): void
    {
        $this->write($sessionToken, $programmeId, register: true);
    }

    public function unregister(string $sessionToken, int $programmeId): void
    {
        $this->write($sessionToken, $programmeId, register: false);
    }

    public function revokeSession(string $sessionToken): void
    {
        $this->participantId($sessionToken);
        $this->mutate(static function (array $state) use ($sessionToken): array {
            $state['revoked'][] = $sessionToken;

            return $state;
        });
    }

    /** kissj's order of checks: the session, the window, then notFound, notPossible, already/notRegistered. */
    private function write(string $sessionToken, int $programmeId, bool $register): void
    {
        $participantId = $this->participantId($sessionToken);
        if (!$this->getRegistrationWindow()->isOpen(new \DateTimeImmutable())) {
            throw new RegistrationRefusedException('closed');
        }
        $programme = null;
        foreach ($this->getPrograms() as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $programmeId) {
                $programme = $candidate;
                break;
            }
        }
        if ($programme === null) {
            throw new RegistrationRefusedException('notFound');
        }
        if ($programme['preregistered']) {
            throw new RegistrationRefusedException('notPossible');
        }

        $registeredIn = fn (array $state): array => $this->registrations($state)[$participantId] ?? [];
        // checked under the lock, so of a double tap the second one is refused; a refusal
        // throws out of the change, and mutate() then writes nothing
        $this->mutate(static function (array $state) use ($participantId, $programmeId, $register, $registeredIn): array {
            $mine = $registeredIn($state);
            $has = in_array($programmeId, $mine, true);
            if ($register && $has) {
                throw new RegistrationRefusedException('alreadyRegistered');
            }
            if (!$register && !$has) {
                throw new RegistrationRefusedException('notRegistered');
            }
            $state['registrations'][(string) $participantId] = $register
                ? [...$mine, $programmeId]
                : array_values(array_diff($mine, [$programmeId]));

            return $state;
        });
    }

    /**
     * The participant a token stands for. A token not minted by this stub, a participant
     * the fixtures do not know, or a revoked token is an expired session.
     */
    private function participantId(string $sessionToken): int
    {
        if (preg_match(self::TOKEN_PATTERN, $sessionToken, $match) !== 1) {
            throw new SessionExpiredException();
        }
        $id = (int) $match[1];
        if ($id !== 1 && !array_key_exists((string) $id, $this->readJson('registered.json'))) {
            throw new SessionExpiredException();
        }
        if (in_array($sessionToken, $this->readState()['revoked'], true)) {
            throw new SessionExpiredException();
        }

        return $id;
    }

    /**
     * registered.json, each participant's list replaced by the one in the state file when
     * the app has written one.
     *
     * @param array{registrations: array<string, list<int>>, revoked: list<string>} $state
     * @return array<int, list<int>>
     */
    private function registrations(array $state): array
    {
        // as kissj: a preregistered programme belongs to a role and holds no registrations,
        // so it is in nobody's list whatever a hand-edited fixture says
        $preregistered = [];
        foreach ($this->getPrograms() as $program) {
            if (is_array($program) && $program['preregistered'] && is_int($program['id'] ?? null)) {
                $preregistered[$program['id']] = true;
            }
        }
        $registrations = [];
        foreach ([$this->readJson('registered.json'), $state['registrations']] as $source) {
            foreach ($source as $participantId => $programmeIds) {
                if (is_array($programmeIds)) {
                    $registrations[(int) $participantId] = array_values(array_filter(
                        $programmeIds,
                        static fn (mixed $id): bool => is_int($id) && !isset($preregistered[$id]),
                    ));
                }
            }
        }

        return $registrations;
    }

    /** @return array{registrations: array<string, list<int>>, revoked: list<string>} */
    private function readState(): array
    {
        $json = @file_get_contents($this->statePath());

        return self::state($json === false ? '' : $json);
    }

    /**
     * Read, change and write the state file under one exclusive lock, so two workers
     * writing at once (a double tap) each see the other's write.
     *
     * @param \Closure(array{registrations: array<string, list<int>>, revoked: list<string>}): array $change
     */
    private function mutate(\Closure $change): void
    {
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0o777, true) && !is_dir($this->stateDir)) {
            throw new LocalStateException('Cannot create the local programme state directory');
        }
        $handle = @fopen($this->statePath(), 'c+');
        if ($handle === false) {
            throw new LocalStateException('Cannot open the local programme state file');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new LocalStateException('Cannot lock the local programme state file');
            }
            $state = $change(self::state((string) stream_get_contents($handle)));
            $json = json_encode(
                ['registrations' => (object) $state['registrations'], 'revoked' => array_values($state['revoked'])],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            if (!ftruncate($handle, 0) || !rewind($handle) || @fwrite($handle, $json . "\n") === false || !fflush($handle)) {
                throw new LocalStateException('Cannot write the local programme state file');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{registrations: array<string, list<int>>, revoked: list<string>} */
    private static function state(string $json): array
    {
        $state = json_decode($json, true);
        if (!is_array($state)) {
            $state = [];
        }

        return [
            'registrations' => is_array($state['registrations'] ?? null) ? $state['registrations'] : [],
            'revoked' => is_array($state['revoked'] ?? null) ? array_values(array_filter($state['revoked'], 'is_string')) : [],
        ];
    }

    /** events/<slug>/fixtures → <stateDir>/<slug>.json */
    private function statePath(): string
    {
        return $this->stateDir . '/' . basename(dirname($this->fixturesDir)) . '.json';
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $instant = is_string($value) ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;
        if ($instant === false) {
            throw new ProgramDataException('registration.json holds a time that is not DATE_ATOM');
        }

        return $instant;
    }

    /**
     * Memoised: a logged-in request reads programs.json for the screen and again for the
     * participant, and the provider lives for exactly one request, so there is no
     * staleness to weigh against the saved read and decode.
     */
    private function readJson(string $file): array
    {
        if (!isset($this->decoded[$file])) {
            $path = $this->fixturesDir . '/' . $file;
            $this->decoded[$file] = is_file($path)
                ? (array) json_decode((string) file_get_contents($path), true)
                : [];
        }

        return $this->decoded[$file];
    }
}
