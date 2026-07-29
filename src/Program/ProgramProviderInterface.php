<?php

declare(strict_types=1);

namespace App\Program;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;

interface ProgramProviderInterface
{
    /** @return list<array> programy v interním tvaru */
    public function getPrograms(): array;

    /**
     * @return list<array>
     * @throws UnknownParticipantException pokud účastník neexistuje
     */
    public function getProgramsForIdentity(Identity $identity): array;
}
