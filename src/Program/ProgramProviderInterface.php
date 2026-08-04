<?php

declare(strict_types=1);

namespace App\Program;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;

interface ProgramProviderInterface
{
    /** @return list<array> programs in the internal shape */
    public function getPrograms(): array;

    /**
     * @return list<array>
     * @throws UnknownParticipantException when the participant does not exist
     */
    public function getProgramsForIdentity(Identity $identity): array;
}
