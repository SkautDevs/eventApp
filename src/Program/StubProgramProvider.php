<?php

declare(strict_types=1);

namespace App\Program;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;

final class StubProgramProvider implements ProgramProviderInterface
{
    /** @var array<string, array> decoded fixtures, kept for the life of the request */
    private array $decoded = [];

    public function __construct(private readonly string $fixturesDir)
    {
    }

    public function getPrograms(): array
    {
        return $this->readJson('programs.json');
    }

    /**
     * fixtures/sections.json is kissj's own `sections` list, so it goes through the same
     * mapping — and a fixture that would fail against kissj fails here too.
     */
    public function getSections(): array
    {
        return Sections::fromKissj($this->readJson('sections.json'));
    }

    public function getProgramsForIdentity(Identity $identity): array
    {
        $map = $this->readJson('registered.json');
        $key = 'tie:' . $identity->tieCode;

        if (!isset($map[$key])) {
            throw new UnknownParticipantException(sprintf('Unknown TIE code: %s', $identity->tieCode));
        }

        $ids = $map[$key];

        return array_values(array_filter(
            $this->getPrograms(),
            fn (array $program): bool => in_array($program['id'], $ids, true),
        ));
    }

    public function getTieCodesForProgramme(int $programmeId): array
    {
        $codes = [];
        foreach ($this->readJson('registered.json') as $key => $ids) {
            if (str_starts_with((string) $key, 'tie:') && is_array($ids) && in_array($programmeId, $ids, true)) {
                $codes[] = strtoupper(substr((string) $key, 4));
            }
        }

        return $codes;
    }

    /**
     * Memoised: a logged-in request asks for programs.json twice — once for the screen
     * and once through getProgramsForIdentity() — and the provider lives for exactly one
     * request, so there is no staleness to weigh against the saved read and decode.
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
