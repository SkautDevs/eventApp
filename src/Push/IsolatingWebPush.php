<?php

declare(strict_types=1);

namespace App\Push;

use Minishlink\WebPush\WebPush;

/**
 * The library's sender with one change: a notification whose encryption throws is set
 * aside instead of taking the batch down. The library prepares a whole batch before it
 * sends any of it, so one unusable subscription used to cost every reader the message,
 * and the throw comes before anything is sent — setting the row aside here sends nobody
 * anything twice. WebPushSender drops the rows set aside, unless all of them were.
 */
final class IsolatingWebPush extends WebPush
{
    /** @var list<array{endpoint: string, error: \Throwable}> */
    private array $unprepared = [];

    protected function prepare(array $notifications): array
    {
        $requests = [];
        foreach ($notifications as $notification) {
            try {
                array_push($requests, ...parent::prepare([$notification]));
            } catch (\Throwable $e) {
                $this->unprepared[] = ['endpoint' => $notification->getSubscription()->getEndpoint(), 'error' => $e];
            }
        }

        return $requests;
    }

    /** @return list<array{endpoint: string, error: \Throwable}> the notifications set aside since the last call */
    public function takeUnprepared(): array
    {
        $unprepared = $this->unprepared;
        $this->unprepared = [];

        return $unprepared;
    }
}
