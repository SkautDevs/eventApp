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
            $body = (array) $request->getParsedBody();
            $event = $this->get(\App\EventConfig::class);
            $repository = $this->get(SubscriptionRepository::class);
            $endpoint = is_string($body['endpoint'] ?? null) ? $body['endpoint'] : '';
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
                    $repository->delete($endpoint);
                    $response->getBody()->write(json_encode(['ok' => false, 'welcome' => false]));

                    return $response->withHeader('Content-Type', 'application/json')->withStatus(502);
                }
            }

            $response->getBody()->write(json_encode(['ok' => true, 'welcome' => $welcome]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        })->setName('push-subscribe');

        // Shareable admin link: /admin/notify?token=<ADMIN_TOKEN> (holding the link is the access)
        $tokenValid = function ($request) use ($app): bool {
            $expected = $app->getContainer()->get(\App\EventConfig::class)->env('ADMIN_TOKEN');
            $given = (string) ($request->getQueryParams()['token']
                ?? ((array) $request->getParsedBody())['token']
                ?? '');

            return $expected !== '' && hash_equals($expected, $given);
        };

        $render = function ($request, $response, array $context = []) use ($app) {
            $c = $app->getContainer();
            $slug = $c->get(\App\EventConfig::class)->slug;
            $messages = $c->get(\App\Push\MessageRepository::class);
            $params = $request->getMethod() === 'GET' ? $request->getQueryParams() : (array) $request->getParsedBody();
            $page = max(1, (int) ($params['page'] ?? 1));
            [$programmes, $unavailable] = self::programmes($c);

            // the result of a send is flashed by the POST and shown once, on the GET it redirects to
            $session = $c->get(\App\Session::class);
            $flash = $request->getMethod() === 'GET' ? $session->get('notifyResult') : null;
            if ($flash !== null) {
                $session->delete('notifyResult');
            }

            return $c->get(\Slim\Views\Twig::class)->render($response, 'admin-notify.twig', $context + [
                'token' => (string) ($params['token'] ?? ''),
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

        $app->get('/admin/notify', function ($request, $response) use ($tokenValid, $render) {
            if (!$tokenValid($request)) {
                return $response->withStatus(403);
            }

            return $render($request, $response);
        })->setName('admin-notify');

        $app->post('/admin/notify', function ($request, $response) use ($tokenValid, $render, $app) {
            if (!$tokenValid($request)) {
                return $response->withStatus(403);
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

            // Post/Redirect/Get: a refresh of the result must not send the message again. The
            // next message usually comes from the same person, so only Podpis is kept.
            $this->get(\App\Session::class)->set('notifyResult', [
                'result' => ['sent' => $result['sent'], 'removed' => $result['removed'], 'unreached' => $unreached],
                'signature' => $values['signature'],
            ]);
            $location = $app->getRouteCollector()->getRouteParser()->urlFor('admin-notify', [], [
                'token' => (string) ($body['token'] ?? ''),
            ]);

            return $response->withHeader('Location', $location)->withStatus(303);
        });

        $app->post('/admin/notify/{id:[0-9]+}/hidden', function ($request, $response, array $args) use ($tokenValid, $render, $app) {
            if (!$tokenValid($request)) {
                return $response->withStatus(403);
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
                'token' => (string) ($body['token'] ?? ''),
                'page' => max(1, (int) ($body['page'] ?? 1)),
            ]);

            return $response->withHeader('Location', $location)->withStatus(303);
        })->setName('admin-notify-hidden');
    }

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
