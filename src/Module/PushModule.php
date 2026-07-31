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
    }
}
