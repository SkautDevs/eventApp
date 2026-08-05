<?php

declare(strict_types=1);

namespace App\Program;

/**
 * A provider answered, but with something that is not programme data: the wrong
 * shape, a missing id or name, an unparseable date. It is the arrived-but-wrong
 * counterpart to Guzzle's TransferException (never arrived), and callers are
 * expected to handle the two together — a 200 carrying nonsense degrades to the
 * same notice as a connection that failed.
 */
final class ProgramDataException extends \RuntimeException
{
}
