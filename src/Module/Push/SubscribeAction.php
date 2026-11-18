<?php

declare(strict_types=1);

namespace App\Module\Push;

use App\Auth\Authenticator;
use App\EventConfig;
use App\Http\ClientIp;
use App\Push\EndpointPolicy;
use App\Push\PushSenderInterface;
use App\Push\SendOutcome;
use App\Push\SubscribeThrottle;
use App\Push\SubscriptionKeys;
use App\Push\SubscriptionRepository;
use App\Telemetry\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * POST /push/subscribe: saves the browser's subscription for this event, tagged with the
 * paired participant from the session (never from the body), and greets a new one with a
 * welcome notification. The repository and the throttle are lazy, so nothing opens the
 * database before the endpoint and the keys have passed.
 *
 * `POST /push/subscribe` accepts only endpoints on the known push services
 * (`Push\EndpointPolicy`, extended by `PUSH_ENDPOINT_HOSTS`), because the welcome is a POST
 * to that URL; only keys `Push\SubscriptionKeys::valid()` accepts — a 16-byte auth secret
 * and a `p256dh` that OpenSSL parses as a point on P-256, since a 65-byte `0x04…` value off
 * the curve passes every shape check and then throws inside the library's batch encryption
 * — or it answers 400 `{"saved":false,"error":"invalid-key"}`; and at most
 * `SubscribeThrottle::LIMIT` (300, room for a camp behind one NAT) **new** subscriptions
 * per `(event, throttleKey)` in 10 minutes, then 429 `{"saved":false,"error":"too-many"}` —
 * a known one never counts, a new one counts once saved even if its welcome is then
 * rejected.
 *
 * `PushSenderInterface::sendToSubscription()` returns `Push\SendOutcome` — `Delivered`,
 * `Rejected` (the push service answered 404 or 410, or the row can never be delivered:
 * refused by the allow-list, malformed keys, or no longer stored) or `Failed` (a timeout,
 * any other status, an exception) — and `POST /push/subscribe` answers a new subscription
 * accordingly: 201 `{"saved":true,"welcome":true}`; 201 `{"saved":true,"welcome":false}`
 * with the row kept, because a failure proves nothing about it; or, for `Rejected`, the row
 * deleted and 502 `{"saved":false,"welcome":false,"error":"subscription-rejected"}`.
 *
 * A known subscription (the re-send after a login or logout) is 201
 * `{"saved":true,"welcome":null}` and sends nothing.
 */
final class SubscribeAction
{
    public function __construct(
        private readonly EventConfig $event,
        private readonly SubscriptionRepository $repository,
        private readonly EndpointPolicy $endpoints,
        private readonly SubscribeThrottle $throttle,
        private readonly ClientIp $clientIp,
        private readonly Authenticator $auth,
        private readonly PushSenderInterface $sender,
    ) {}

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $event = $this->event;

        $answer = Tracer::span('push.subscribe', 'subscribe ' . $event->slug, function () use ($request, $response, $event): ResponseInterface {
            $body = (array) $request->getParsedBody();
            $repository = $this->repository;
            $endpoint = is_string($body['endpoint'] ?? null) ? $body['endpoint'] : '';
            // the welcome below is a POST to this URL, so only the known push services may be named
            if (!$this->endpoints->allows($endpoint)) {
                return $response->withStatus(400);
            }
            // A key that is not a P-256 point passes every shape check and then throws inside
            // the library's batch encryption — it used to cost every reader the next message.
            $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];
            if (!SubscriptionKeys::valid($keys['p256dh'] ?? null, $keys['auth'] ?? null)) {
                return self::json($response, 400, ['saved' => false, 'error' => 'invalid-key']);
            }
            $isNew = $repository->find($event->slug, $endpoint) === null;
            $throttle = $this->throttle;
            $address = $this->clientIp->throttleKey($request);
            if ($isNew && $throttle->tooMany($event->slug, $address)) {
                return self::json($response, 429, ['saved' => false, 'error' => 'too-many']);
            }
            try {
                // The participant comes from the session only: the route is unauthenticated, so an
                // id in the body would let anyone receive another participant's programme messages.
                $repository->save(
                    $body,
                    $event->slug,
                    $this->auth->identity()?->participantId,
                );
            } catch (\InvalidArgumentException) {
                return $response->withStatus(400);
            }
            if ($isNew) {
                $throttle->record($event->slug, $address);
            }

            Tracer::spanTag('push.new', $isNew ? '1' : '0');

            // The re-send after a login or logout is not new and stays silent. null: nothing was sent.
            if (!$isNew) {
                return self::json($response, 201, ['saved' => true, 'welcome' => null]);
            }

            // A new subscription gets a welcome at once, so the reader sees on the very first tap
            // that notifications arrive — rather than finding out at the first real message.
            try {
                $outcome = $this->sender->sendToSubscription(
                    event: $event->slug,
                    endpoint: $endpoint,
                    title: $event->name,
                    body: 'Notifikace jsou zapnuté. Další notifikace z akce ti budou chodit sem.',
                    icon: $event->assets->notificationIcon,
                    url: RouteContext::fromRequest($request)->getBasePath() . '/informace',
                );
            } catch (\Throwable) {
                // an exception proves nothing about the subscription
                $outcome = SendOutcome::Failed;
            }

            // The push service disowned the subscription: the row goes, the browser lets go of
            // it too (push.js), and the reader's next tap is new again, welcome included.
            if ($outcome === SendOutcome::Rejected) {
                $repository->delete($event->slug, $endpoint);

                return self::json($response, 502, ['saved' => false, 'welcome' => false, 'error' => 'subscription-rejected']);
            }

            // Failed keeps the row: a timeout or a 500 proves nothing, and the next real send
            // reaches it or removes it the way every send does. The reader is told only what is true.
            return self::json($response, 201, ['saved' => true, 'welcome' => $outcome === SendOutcome::Delivered]);
        });

        return $answer;
    }

    /** @param array<string, mixed> $body */
    private static function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
