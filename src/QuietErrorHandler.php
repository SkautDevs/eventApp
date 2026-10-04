<?php

declare(strict_types=1);

namespace App;

use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Handlers\ErrorHandler;

/**
 * Slim's error handler, minus the log line for a 404 or 405: a reader mistyping a URL
 * is not something an operator needs in the error log. A real error goes to Sentry
 * through the Collector and then to the `errors` log channel, which has no Sentry
 * handler — the exception is already filed once.
 */
final class QuietErrorHandler extends ErrorHandler
{
    protected function writeToErrorLog(): void
    {
        if ($this->exception instanceof HttpNotFoundException || $this->exception instanceof HttpMethodNotAllowedException) {
            return;
        }
        Telemetry\Collector::collect($this->exception);
        parent::writeToErrorLog();
    }
}
