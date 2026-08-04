<?php

declare(strict_types=1);

namespace App\Program;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;

final class StubProgramProvider implements ProgramProviderInterface
{
    public function __construct(private readonly string $fixturesDir)
    {
    }

    public function getPrograms(): array
    {
        return $this->readJson('programs.json');
    }

    public function getProgramsForIdentity(Identity $identity): array
    {
        $map = $this->readJson('registered.json');
        $key = $identity->type === 'skautis'
            ? 'skautis:' . $identity->skautisUserId
            : 'tie:' . $identity->tieCode;

        if (!isset($map[$key])) {
            if ($identity->type === 'tie') {
                throw new UnknownParticipantException(sprintf('Unknown TIE code: %s', $identity->tieCode));
            }

            return [];
        }

        $ids = $map[$key];

        return array_values(array_filter(
            $this->getPrograms(),
            fn (array $program): bool => in_array($program['id'], $ids, true),
        ));
    }

    private function readJson(string $file): array
    {
        $path = $this->fixturesDir . '/' . $file;

        return is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    }
}
