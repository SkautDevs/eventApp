<?php

declare(strict_types=1);

namespace App\Program;

use GuzzleHttp\Exception\TransferException;

/**
 * A kissj call that failed: a status the contract does not give a meaning, or no answer at
 * all. Guzzle's own message names the request URI and quotes the response body, and Sentry
 * reports every chained exception's message too — so this one names the endpoint in words
 * and chains nothing, and no device session token or code can ride along. It is a
 * TransferException, so every caller that degrades on an outage still does.
 */
final class KissjTransferException extends TransferException
{
    /**
     * @param string   $where  what the message names instead of the path, e.g. "the session endpoint"
     * @param int|null $status the HTTP status kissj answered, null when it never answered
     */
    public static function on(string $where, ?int $status, \Throwable $cause): self
    {
        return new self($status === null
            ? sprintf('kissj could not be reached on %s (%s)', $where, (new \ReflectionClass($cause))->getShortName())
            : sprintf('kissj answered HTTP %d on %s', $status, $where));
    }
}
