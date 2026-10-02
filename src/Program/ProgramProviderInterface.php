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
     * The event's programme sections — the detail sheet names a programme's section,
     * and a programme whose section is not listed here is not shown.
     *
     * @return array<int, array{id: int, title: string, subTitle: ?string, image: ?string, attachment: ?array{href: string, label: string}}>
     *         keyed by id, in display order
     */
    public function getSections(): array;

    /**
     * @return list<array>
     * @throws UnknownParticipantException when the participant does not exist
     */
    public function getProgramsForIdentity(Identity $identity): array;

    /**
     * The TIE codes of every participant registered for this programme, upper-cased.
     *
     * @return list<string>
     * @throws \GuzzleHttp\Exception\TransferException|ProgramDataException when the provider fails
     */
    public function getTieCodesForProgramme(int $programmeId): array;
}
