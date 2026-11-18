<?php

declare(strict_types=1);

namespace App\Program;

/**
 * The programme source did not answer: kissj could not be reached or answered with a
 * status the contract gives no meaning (KissjTransferException), the `local` source's
 * state file could not be written (LocalStateException), or a request waited for another
 * one that failed (CachingProgramProvider). It is the never-arrived counterpart to
 * ProgramDataException, and callers handle the two together: an outage degrades to a
 * notice, never a 500.
 *
 * The app's own type, not Guzzle's TransferException, because since Guzzle 8 that one
 * belongs to a request, and two of the three causes above have none.
 */
class ProgramUnavailableException extends \RuntimeException {}
