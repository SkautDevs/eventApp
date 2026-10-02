<?php

declare(strict_types=1);

namespace App;

use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Handlers\ErrorHandler;

/**
 * Slim's error handler, minus the log line for a 404 or 405: a reader mistyping a URL
 * is not something an operator needs in the error log. Real errors are still logged.
 */
final class QuietErrorHandler extends ErrorHandler
{
    protected function writeToErrorLog(): void
    {
        if ($this->exception instanceof HttpNotFoundException || $this->exception instanceof HttpMethodNotAllowedException) {
            return;
        }
        parent::writeToErrorLog();
    }
}
