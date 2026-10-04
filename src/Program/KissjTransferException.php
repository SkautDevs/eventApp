<?php

declare(strict_types=1);

namespace App\Program;

use GuzzleHttp\Exception\TransferException;

/**
 * A kissj call that failed on an endpoint whose path carries a TIE code. Guzzle's own
 * message names the request URI, code included, and Sentry reports every chained
 * exception's message too — so this one says what happened in words and chains nothing.
 * It is a TransferException, so every caller that degrades on an outage still does.
 */
final class KissjTransferException extends TransferException
{
    /**
     * @param string   $where  what the message names instead of the path, e.g. "the participant endpoint"
     * @param int|null $status the HTTP status kissj answered, null when it never answered
     */
    public static function on(string $where, ?int $status, \Throwable $cause): self
    {
        return new self($status === null
            ? sprintf('kissj could not be reached on %s (%s)', $where, (new \ReflectionClass($cause))->getShortName())
            : sprintf('kissj answered HTTP %d on %s', $status, $where));
    }
}
