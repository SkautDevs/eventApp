<?php

declare(strict_types=1);

namespace App\Program;


/**
 * The `local` source's state file (StubProgramProvider) could not be created, locked or
 * written. To the app it is what kissj not answering is: a ProgramUnavailableException, so a
 * register or unregister says "zkus to prosím později" instead of failing with a 500.
 */
final class LocalStateException extends ProgramUnavailableException
{
}
