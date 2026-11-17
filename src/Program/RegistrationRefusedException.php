<?php

declare(strict_types=1);

namespace App\Program;

/**
 * kissj said no to a register or unregister, with one of the contract's machine codes
 * (`notPaid`, `closed`, `notFound`, `notPossible`, `wrongRole`, `alreadyRegistered`,
 * `notRegistered`, `conflict`, `full`). An answer, not an outage.
 */
final class RegistrationRefusedException extends \RuntimeException
{
    /** @param string $refusal the code; \Exception::$code is an int, so it lives here */
    public function __construct(public readonly string $refusal)
    {
        parent::__construct(sprintf('kissj refused the registration: %s', $refusal));
    }
}
