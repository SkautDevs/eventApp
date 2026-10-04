<?php

declare(strict_types=1);

namespace App\Module;

use App\Push\SubscriptionRepository;
use Slim\App;

final class PushModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'push';
    }

    public function menuItem(): ?array
    {
        return null;
    }

    public function registerRoutes(App $app): void
    {
        $app->post('/push/subscribe', function ($request, $response) use ($app) {
            $event = $this->get(\App\EventConfig::class);

            return \App\Telemetry\Tracer::span('push.subscribe', 'subscribe ' . $event->slug, function () use ($request, $response, $app, $event) {
                $body = (array) $request->getParsedBody();
                $repository = $this->get(SubscriptionRepository::class);
                $endpoint = is_string($body['endpoint'] ?? null) ? $body['endpoint'] : '';
                // the welcome below is a POST to this URL, so only the known push services may be named
                if (!$this->get(\App\Push\EndpointPolicy::class)->allows($endpoint)) {
                    return $response->withStatus(400);
                }
                $isNew = $repository->find($event->slug, $endpoint) === null;
                try {
                    // The code comes from the session only: the route is unauthenticated, so a code in the
                    // body would let anyone receive another participant's programme messages.
                    $repository->save(
                        $body,
                        $event->slug,
                        $this->get(\App\Auth\Authenticator::class)->identity()?->tieCode,
                    );
                } catch (\InvalidArgumentException) {
                    return $response->withStatus(400);
                }

                // A new subscription gets a welcome at once, so the reader sees on the very first tap
                // that notifications arrive — rather than finding out at the first real message. The
                // re-send after a login or logout is not new and stays silent. null: nothing was sent.
                $welcome = null;
                if ($isNew) {
                    try {
                        $welcome = $this->get(\App\Push\PushSenderInterface::class)->sendToSubscription(
                            event: $event->slug,
                            endpoint: $endpoint,
                            title: $event->name,
                            body: 'Notifikace jsou zapnuté. Novinky z akce ti budou chodit sem.',
                            icon: $event->get('assets')['notificationIcon'] ?? null,
                            url: $app->getBasePath() . '/novinky',
                        );
                    } catch (\Throwable) {
                        $welcome = false;
                    }
                    // A subscription whose welcome did not get through is not kept: the reader is told
                    // it failed and taps again, and that tap is new again, welcome included.
                    if (!$welcome) {
                        $repository->delete($event->slug, $endpoint);
                        $response->getBody()->write(json_encode(['ok' => false, 'welcome' => false]));

                        return $response->withHeader('Content-Type', 'application/json')->withStatus(502);
                    }
                }

                $response->getBody()->write(json_encode(['ok' => true, 'welcome' => $welcome]));

                return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
            });
        })->setName('push-subscribe');

        // The shared link /admin/notify?token=<ADMIN_TOKEN_<SLUG>> logs the browser in for a
        // day and redirects to the bare URL, so the token never sits in history, a referrer
        // or a Sentry transaction. The forms carry a CSRF token instead.
        $isAdmin = function () use ($app): bool {
            $c = $app->getContainer();
            // removing the token from the config closes the page for sessions already open
            if ($c->get(\App\EventConfig::class)->env('ADMIN_TOKEN') === '') {
                return false;
            }
            $flag = $c->get(\App\Session::class)->get('admin');
            if (!is_array($flag) || !is_int($flag['since'] ?? null)) {
                return false;
            }
            $age = time() - $flag['since'];

            return $age >= 0 && $age < self::ADMIN_SESSION_SECONDS;
        };

        $csrfValid = function ($request) use ($app): bool {
            $expected = $app->getContainer()->get(\App\Session::class)->get('csrf');
            $given = ((array) $request->getParsedBody())['csrf'] ?? null;

            return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
        };

        // every admin response, a 403 included: nothing of it belongs in a cache, and no
        // page it links to needs to know where the organiser came from
        $adminHeaders = function ($request, $handler) {
            return $handler->handle($request)
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Referrer-Policy', 'no-referrer');
        };

        $render = function ($request, $response, array $context = []) use ($app) {
            $c = $app->getContainer();
            $slug = $c->get(\App\EventConfig::class)->slug;
            $messages = $c->get(\App\Push\MessageRepository::class);
            $session = $c->get(\App\Session::class);
            $params = $request->getMethod() === 'GET' ? $request->getQueryParams() : (array) $request->getParsedBody();
            $page = max(1, (int) ($params['page'] ?? 1));
            [$programmes, $unavailable] = self::programmes($c);

            // the result of a send is flashed by the POST and shown once, on the GET it redirects to
            $flash = $request->getMethod() === 'GET' ? $session->get('notifyResult') : null;
            if ($flash !== null) {
                $session->delete('notifyResult');
            }

            return $c->get(\Slim\Views\Twig::class)->render($response, 'admin-notify.twig', $context + [
                'csrf' => (string) $session->get('csrf', ''),
                'result' => $flash['result'] ?? null,
                'errors' => [],
                'values' => ['target' => '', 'title' => '', 'body' => '', 'signature' => $flash['signature'] ?? ''],
                'groups' => self::programmeOptions($programmes),
                'programmesUnavailable' => $unavailable,
                'log' => $messages->page($slug, $page, self::LOG_PAGE),
                'page' => $page,
                'hasOlder' => $messages->count($slug) > $page * self::LOG_PAGE,
            ]);
        };

        // A refused POST (the day is up, or the CSRF token no longer matches) says so in words
        // and hands back what was typed, because Back cannot: the admin page is no-store, and
        // its GET is refused for the same reason. The hide/show forms carry no text to return.
        $refused = function ($request, $response) use ($app) {
            $body = (array) $request->getParsedBody();
            $echo = [];
            foreach (['title', 'body'] as $field) {
                if (is_string($body[$field] ?? null) && trim($body[$field]) !== '') {
                    $echo[$field] = $body[$field];
                }
            }

            return $app->getContainer()->get(\Slim\Views\Twig::class)
                ->render($response->withStatus(403), 'admin-expired.twig', ['message' => $echo]);
        };

        $app->get('/admin/notify', function ($request, $response) use ($isAdmin, $render, $app) {
            $query = $request->getQueryParams();
            if (array_key_exists('token', $query)) {
                $expected = $this->get(\App\EventConfig::class)->env('ADMIN_TOKEN');
                $given = is_string($query['token']) ? $query['token'] : '';
                if ($expected === '' || !hash_equals($expected, $given)) {
                    return $response->withStatus(403);
                }
                $session = $this->get(\App\Session::class);
                // a privilege change, so the session ID changes with it (see Authenticator::store())
                $session->regenerateId();
                $session->set('admin', ['since' => time()]);
                // Re-opening the link (a second tab, the team chat) must not invalidate a form
                // already open elsewhere, so a well-formed token survives the hop.
                $csrf = $session->get('csrf');
                $session->set('csrf', is_string($csrf) && preg_match('/^[0-9a-f]{32}$/', $csrf) === 1
                    ? $csrf
                    : bin2hex(random_bytes(16)));

                return $response
                    ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('admin-notify'))
                    ->withStatus(303);
            }
            if (!$isAdmin()) {
                return $response->withStatus(403);
            }

            return $render($request, $response);
        })->setName('admin-notify')->add($adminHeaders);

        $app->post('/admin/notify', function ($request, $response) use ($isAdmin, $csrfValid, $render, $refused, $app) {
            if (!$isAdmin() || !$csrfValid($request)) {
                return $refused($request, $response);
            }
            $body = (array) $request->getParsedBody();
            $values = [
                'target' => trim((string) ($body['target'] ?? '')),
                'title' => trim((string) ($body['title'] ?? '')),
                'body' => trim((string) ($body['body'] ?? '')),
                'signature' => trim((string) ($body['signature'] ?? '')),
            ];

            $programmesById = [];
            foreach (self::programmes($this)[0] as $programme) {
                $programmesById[(string) $programme['id']] = $programme;
            }
            $errors = self::validate($values, $programmesById);
            if ($errors !== []) {
                return $render($request, $response->withStatus(422), ['errors' => $errors, 'values' => $values]);
            }

            $event = $this->get(\App\EventConfig::class);
            $base = $app->getBasePath();
            $programme = $programmesById[$values['target']] ?? null;
            $tieCodes = null;
            if ($programme !== null) {
                try {
                    $tieCodes = $this->get(\App\Program\ProgramProviderInterface::class)
                        ->getTieCodesForProgramme((int) $programme['id']);
                } catch (\GuzzleHttp\Exception\TransferException|\App\Program\ProgramDataException) {
                    return $render($request, $response->withStatus(502), [
                        'errors' => ['Nepodařilo se načíst přihlášené z kissj, nic nebylo odesláno.'],
                        'values' => $values,
                    ]);
                }
            }

            $result = $this->get(\App\Push\PushSenderInterface::class)->sendToEvent(
                event: $event->slug,
                title: $values['title'],
                body: $values['body'],
                icon: $event->get('assets')['notificationIcon'] ?? null,
                url: $programme === null
                    ? $base . '/novinky'
                    : sprintf('%s/programy#section-%d-program-%d', $base, $programme['section']['id'] ?? 0, $programme['id']),
                tieCodes: $tieCodes,
            );
            $unreached = $tieCodes === null
                ? null
                : count(array_diff($tieCodes, $this->get(SubscriptionRepository::class)->subscribedTieCodes($event->slug)));
            $this->get(\App\Push\MessageRepository::class)->add(
                event: $event->slug,
                programmeId: $programme === null ? null : (int) $programme['id'],
                targetLabel: $programme === null ? 'Všem' : (string) $programme['name'],
                title: $values['title'],
                body: $values['body'],
                signature: $values['signature'],
                sent: $result['sent'],
                removed: $result['removed'],
                unreached: $unreached,
            );
            // a record on stdout even with Sentry off; no TIE code, no title, no body
            $this->get(\Psr\Log\LoggerInterface::class)->info('push.sent', [
                'event' => $event->slug,
                'sent' => $result['sent'],
                'removed' => $result['removed'],
                'programme' => $programme === null ? null : (int) $programme['id'],
            ]);

            // Post/Redirect/Get: a refresh of the result must not send the message again. The
            // next message usually comes from the same person, so only Podpis is kept.
            $this->get(\App\Session::class)->set('notifyResult', [
                'result' => ['sent' => $result['sent'], 'removed' => $result['removed'], 'unreached' => $unreached],
                'signature' => $values['signature'],
            ]);

            return $response
                ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('admin-notify'))
                ->withStatus(303);
        })->add($adminHeaders);

        $app->post('/admin/notify/{id:[0-9]+}/hidden', function ($request, $response, array $args) use ($isAdmin, $csrfValid, $render, $refused, $app) {
            if (!$isAdmin() || !$csrfValid($request)) {
                return $refused($request->withParsedBody([]), $response);
            }
            $body = (array) $request->getParsedBody();
            $signature = trim((string) ($body['signature'] ?? ''));
            if (!self::signatureValid($signature)) {
                return $render($request, $response->withStatus(422), ['errors' => [self::SIGNATURE_ERROR]]);
            }

            $changed = $this->get(\App\Push\MessageRepository::class)->setHidden(
                $this->get(\App\EventConfig::class)->slug,
                (int) $args['id'],
                (string) ($body['hidden'] ?? '') === '1',
                $signature,
            );
            if (!$changed) {
                return $response->withStatus(404);
            }

            $location = $app->getRouteCollector()->getRouteParser()->urlFor('admin-notify', [], [
                'page' => max(1, (int) ($body['page'] ?? 1)),
            ]);

            return $response->withHeader('Location', $location)->withStatus(303);
        })->setName('admin-notify-hidden')->add($adminHeaders);
    }

    /** How long the shared link keeps a browser logged in: what a phone left on a table can do. */
    public const ADMIN_SESSION_SECONDS = 86400;

    private const LOG_PAGE = 50;

    private const SIGNATURE_ERROR = 'Podpis je povinný a smí mít nejvýš 40 znaků.';

    /**
     * The event's programmes for the picker; a provider outage leaves only "everyone".
     *
     * @return array{0: list<array>, 1: bool} the programmes, and whether they could not be read
     */
    private static function programmes(\Psr\Container\ContainerInterface $c): array
    {
        try {
            return [$c->get(\App\Program\ProgramProviderInterface::class)->getPrograms(), false];
        } catch (\GuzzleHttp\Exception\TransferException|\App\Program\ProgramDataException) {
            return [[], true];
        }
    }

    /**
     * One group per calendar day, programmes in start order. The group carries its first
     * programme's start so the template can name the day with the dateToCzechDayName filter.
     *
     * @return list<array{start: array, label: string, options: list<array{id: int, label: string}>}>
     */
    private static function programmeOptions(array $programs): array
    {
        usort($programs, fn (array $a, array $b): int => [$a['start']['date'], $a['id']] <=> [$b['start']['date'], $b['id']]);

        $groups = [];
        foreach ($programs as $programme) {
            $start = new \DateTimeImmutable($programme['start']['date']);
            $day = $start->format('Y-m-d');
            $groups[$day] ??= ['start' => $programme['start'], 'label' => $start->format('j. n.'), 'options' => []];
            $groups[$day]['options'][] = [
                'id' => (int) $programme['id'],
                'label' => $start->format('H:i') . ' ' . $programme['name']
                    . (($programme['location'] ?? null) !== null ? ' – ' . $programme['location'] : ''),
            ];
        }

        return array_values($groups);
    }

    /**
     * @param array{target: string, title: string, body: string, signature: string} $values
     * @param array<string, array> $programmesById
     * @return list<string> one Czech line per problem
     */
    private static function validate(array $values, array $programmesById): array
    {
        $errors = [];
        if ($values['title'] === '' || mb_strlen($values['title']) > 60) {
            $errors[] = 'Titulek je povinný a smí mít nejvýš 60 znaků.';
        }
        if ($values['body'] === '' || mb_strlen($values['body']) > 500) {
            $errors[] = 'Zpráva je povinná a smí mít nejvýš 500 znaků.';
        }
        if (!self::signatureValid($values['signature'])) {
            $errors[] = self::SIGNATURE_ERROR;
        }
        if ($values['target'] !== '' && !isset($programmesById[$values['target']])) {
            $errors[] = 'Vybraný program neexistuje.';
        }

        return $errors;
    }

    private static function signatureValid(string $signature): bool
    {
        return $signature !== '' && mb_strlen($signature) <= 40;
    }
}
