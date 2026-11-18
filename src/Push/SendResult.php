<?php

declare(strict_types=1);

namespace App\Push;

/**
 * What PushSenderInterface::sendToEvent() came to. `recipients`: the rows the send was
 * addressed to; `sent`: delivered; `removed`: rows found dead and deleted (a 404 or 410,
 * an unusable key, a host off the allow-list); `failed`: neither delivered nor removed (a
 * refusal other than 404/410, a timeout, a throw, or the last of WebPushSender::MAX_FAILURES
 * failures in a row, whose row goes but counts here).
 */
final readonly class SendResult
{
    public function __construct(
        public int $recipients,
        public int $sent,
        public int $removed,
        public int $failed,
    ) {}
}
