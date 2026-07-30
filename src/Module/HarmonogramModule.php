<?php

declare(strict_types=1);

namespace App\Module;

use App\Auth\Authenticator;
use App\Auth\SkautisGatewayInterface;
use App\EventConfig;
use App\Program\ProgramProviderInterface;
use Slim\App;
use Slim\Views\Twig;

final class HarmonogramModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'harmonogram';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Harmonogram', 'route' => 'harmonogram'];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/harmonogram', function ($request, $response) {
            $auth = $this->get(Authenticator::class);
            $gateway = $this->get(SkautisGatewayInterface::class);

            $registered = [];
            if ($auth->isLogged()) {
                foreach ($this->get(ProgramProviderInterface::class)->getProgramsForIdentity($auth->identity()) as $program) {
                    $time = (new \DateTime($program['start']['date']))->format('H:i');
                    $registered[$program['section']['id']][$time] = $program;
                }
            }

            return $this->get(Twig::class)->render($response, 'harmonogram.twig', [
                'schedule' => $this->get(EventConfig::class)->content('schedule'),
                'isLogged' => $auth->isLogged(),
                'identity' => $auth->identity()?->displayName,
                'loginUrl' => $gateway->getLoginUrl('/harmonogram'),
                'logoutUrl' => $gateway->getLogoutUrl('/harmonogram'),
                'registeredPrograms' => $registered,
            ]);
        })->setName('harmonogram');
    }
}
