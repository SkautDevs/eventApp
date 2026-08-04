<?php

declare(strict_types=1);

namespace App\Module;

use App\Auth\Authenticator;
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
        // shares the Program tab with ProgramsModule until the two screens are merged
        return null;
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/harmonogram', function ($request, $response) {
            $auth = $this->get(Authenticator::class);

            $registered = [];
            $notice = null;
            if ($auth->isLogged()) {
                try {
                    foreach ($this->get(ProgramProviderInterface::class)->getProgramsForIdentity($auth->identity()) as $program) {
                        $time = (new \DateTime($program['start']['date']))->format('H:i');
                        $registered[$program['section']['id']][$time] = $program;
                    }
                } catch (\App\Auth\UnknownParticipantException) {
                    $auth->logout();
                    $notice = 'Váš TIE kód už není platný, byli jste odhlášeni.';
                } catch (\GuzzleHttp\Exception\TransferException) {
                    $notice = 'Osobní program se nepodařilo načíst.';
                }
            }

            return $this->get(Twig::class)->render($response, 'harmonogram.twig', [
                'schedule' => $this->get(EventConfig::class)->content('schedule'),
                'isLogged' => $auth->isLogged(),
                'identity' => $auth->identity()?->displayName,
                'registeredPrograms' => $registered,
                'notice' => $notice,
            ]);
        })->setName('harmonogram');
    }
}
