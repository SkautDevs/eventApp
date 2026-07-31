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
        $app->post('/push/subscribe', function ($request, $response) {
            $body = (array) $request->getParsedBody();
            try {
                $this->get(SubscriptionRepository::class)->save($body);
            } catch (\InvalidArgumentException) {
                return $response->withStatus(400);
            }

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        })->setName('push-subscribe');

        // Sdílitelný admin odkaz: /admin/notify?token=<ADMIN_TOKEN> (drž odkaz = máš přístup)
        $tokenValid = static function ($request): bool {
            $expected = $_ENV['ADMIN_TOKEN'] ?? '';
            $given = (string) ($request->getQueryParams()['token']
                ?? ((array) $request->getParsedBody())['token']
                ?? '');

            return $expected !== '' && hash_equals($expected, $given);
        };

        $app->get('/admin/notify', function ($request, $response) use ($tokenValid) {
            if (!$tokenValid($request)) {
                return $response->withStatus(403);
            }

            return $this->get(\Slim\Views\Twig::class)->render($response, 'admin-notify.twig', [
                'result' => null,
                'token' => (string) ($request->getQueryParams()['token'] ?? ''),
            ]);
        })->setName('admin-notify');

        $app->post('/admin/notify', function ($request, $response) use ($tokenValid) {
            if (!$tokenValid($request)) {
                return $response->withStatus(403);
            }
            $body = (array) $request->getParsedBody();

            $event = $this->get(\App\EventConfig::class);
            $result = $this->get(\App\Push\PushSenderInterface::class)->sendToAll(
                title: (string) ($body['title'] ?? $event->name),
                body: (string) ($body['body'] ?? ''),
                icon: $event->get('assets')['notificationIcon'] ?? null,
            );

            return $this->get(\Slim\Views\Twig::class)->render($response, 'admin-notify.twig', [
                'result' => $result,
                'token' => (string) ($body['token'] ?? ''),
            ]);
        });
    }
}
