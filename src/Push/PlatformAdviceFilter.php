<?php

declare(strict_types=1);

namespace App\Push;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The logger web-push gets. Its constructor checks the platform on every send; without
 * GMP or BCMath it advises installing one, which says nothing new after the first time,
 * so that one message is dropped. Everything else — a missing curl or openssl, no P-256,
 * no aes-128-gcm, all warnings — goes through unchanged.
 */
final class PlatformAdviceFilter extends AbstractLogger
{
    public const string DROPPED = 'GMP or BCMath';

    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (str_contains((string) $message, self::DROPPED)) {
            return;
        }
        $this->inner->log($level, $message, $context);
    }
}
