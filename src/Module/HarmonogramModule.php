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

            $session = $this->get(\App\Session::class);
            $tieError = $session->get('tieError');
            $session->delete('tieError');

            return $this->get(Twig::class)->render($response, 'harmonogram.twig', [
                'schedule' => $this->get(EventConfig::class)->content('schedule'),
                'isLogged' => $auth->isLogged(),
                'identity' => $auth->identity()?->displayName,
                'loginUrl' => $gateway->getLoginUrl('/harmonogram'),
                'logoutUrl' => $gateway->getLogoutUrl('/harmonogram'),
                'registeredPrograms' => $registered,
                'tieError' => $tieError,
            ]);
        })->setName('harmonogram');

        $app->post('/harmonogram/tie', function ($request, $response) {
            $code = strtoupper(trim((string) (((array) $request->getParsedBody())['tieCode'] ?? '')));
            $session = $this->get(\App\Session::class);

            if ($code !== '') {
                $identity = new \App\Auth\Identity(type: 'tie', displayName: 'TIE ' . $code, tieCode: $code);
                try {
                    $this->get(\App\Program\ProgramProviderInterface::class)->getProgramsForIdentity($identity);
                    $this->get(\App\Auth\Authenticator::class)->store($identity);
                    $session->delete('tieError');
                } catch (\App\Auth\UnknownParticipantException) {
                    $session->set('tieError', 'Neplatný TIE kód.');
                }
            }

            return $response->withHeader('Location', '/harmonogram')->withStatus(302);
        })->setName('tie-login');

        $app->post('/harmonogram/tie-logout', function ($request, $response) {
            $this->get(\App\Auth\Authenticator::class)->logout();

            return $response->withHeader('Location', '/harmonogram')->withStatus(302);
        })->setName('tie-logout');
    }
}
