<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\Identity;
use App\Program\ProgramProviderInterface;

/** Test program provider that can fail the second and later getProgramsForIdentity() calls. */
final class ThrowingProgramProvider implements ProgramProviderInterface
{
    private int $identityCalls = 0;

    public function __construct(
        private readonly ?\Throwable $programsException = null,
        private readonly ?\Throwable $identityExceptionAfterFirstCall = null,
        private readonly ?\Throwable $identityException = null,
        private readonly ?\Throwable $tieCodesException = null,
    ) {
    }

    public function getPrograms(): array
    {
        if ($this->programsException !== null) {
            throw $this->programsException;
        }

        return [];
    }

    public function getSections(): array
    {
        if ($this->programsException !== null) {
            throw $this->programsException;
        }

        return [];
    }

    public function getProgramsForIdentity(Identity $identity): array
    {
        $this->identityCalls++;

        if ($this->identityException !== null) {
            throw $this->identityException;
        }

        if ($this->identityCalls > 1 && $this->identityExceptionAfterFirstCall !== null) {
            throw $this->identityExceptionAfterFirstCall;
        }

        return [];
    }

    public function getTieCodesForProgramme(int $programmeId): array
    {
        if ($this->tieCodesException !== null) {
            throw $this->tieCodesException;
        }

        return [];
    }
}
