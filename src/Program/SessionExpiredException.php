<?php

declare(strict_types=1);

namespace App\Program;

/**
 * The device session is unknown, expired or revoked: kissj's `401` on a session call.
 * The reader is logged out. The message never names the token and nothing is chained,
 * because both reach logs and Sentry.
 */
final class SessionExpiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Device session is no longer valid');
    }
}
