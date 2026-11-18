<?php

declare(strict_types=1);

namespace App\Push;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The library's sender with two changes.
 *
 * A notification whose encryption throws is set aside instead of taking the batch down.
 * The library prepares a whole batch before it sends any of it, so one unusable
 * subscription used to cost every reader the message, and the throw comes before
 * anything is sent — setting the row aside here sends nobody anything twice.
 * WebPushSender drops the rows set aside, unless all of them were.
 *
 * And a batch is sent concurrently. Since web-push 11 the library's flush() sends one
 * request after another, and its concurrent flushPooled() needs an HTTPlug async client,
 * which no adapter provides for Guzzle 8; one by one, a send to a few thousand
 * subscribers would outlast nginx's 45 s. So flush() here sends the batch through
 * Guzzle's own promises, as web-push 10 did, and yields the reports in queue order.
 */
final class IsolatingWebPush extends WebPush
{
    /** @var list<array{endpoint: string, error: \Throwable}> */
    private array $unprepared = [];

    /**
     * @param array<string, mixed> $auth the library's auth, `['VAPID' => …]`
     * @param Client $http the client every push request goes through; built with
     *                     `http_errors` off, so a 404 or 410 is a report and not a throw
     */
    public function __construct(array $auth, private readonly Client $http, LoggerInterface $logger)
    {
        $factory = new HttpFactory();
        parent::__construct($auth, [], $http, $factory, $factory, null, $logger);
    }

    public function flush(?int $batchSize = null): \Generator
    {
        if ($this->notifications === null || $this->notifications === []) {
            return;
        }

        $batches = array_chunk($this->notifications, $batchSize ?? $this->defaultOptions['batchSize']);
        $this->notifications = [];

        foreach ($batches as $batch) {
            $promises = [];
            foreach ($this->prepare($batch) as $request) {
                $promises[] = $this->http->sendAsync($request)->then(
                    fn(ResponseInterface $response): MessageSentReport => $this->createReport($request, $response),
                    // a failed transfer is a report, as in the library's own flush(); anything
                    // else (a broken handler) is not about this row and propagates
                    fn(\Throwable $reason): MessageSentReport => $reason instanceof ClientExceptionInterface
                        ? $this->createRejectedReport($request, $reason)
                        : throw $reason,
                );
            }
            foreach ($promises as $promise) {
                yield $promise->wait();
            }
        }

        if ($this->reuseVAPIDHeaders) {
            $this->vapidHeaders = [];
        }
    }

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
