<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\Identity;
use App\Program\ProgramProviderInterface;

/** Delegates to a real provider and counts the identity calls — "did the login reach kissj?". */
final class CountingProgramProvider implements ProgramProviderInterface
{
    public int $identityCalls = 0;

    public function __construct(private readonly ProgramProviderInterface $inner)
    {
    }

    public function getPrograms(): array
    {
        return $this->inner->getPrograms();
    }

    public function getSections(): array
    {
        return $this->inner->getSections();
    }

    public function getProgramsForIdentity(Identity $identity): array
    {
        $this->identityCalls++;

        return $this->inner->getProgramsForIdentity($identity);
    }

    public function getTieCodesForProgramme(int $programmeId): array
    {
        return $this->inner->getTieCodesForProgramme($programmeId);
    }
}
