<?php

declare(strict_types=1);

namespace App\Module;

use App\Auth\Authenticator;
use App\Auth\UnknownParticipantException;
use App\EventConfig;
use App\Program\ProgramProviderInterface;
use Slim\App;
use Slim\Views\Twig;

final class ProgramsModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'programs';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Program', 'route' => 'programs', 'icon' => 'far fa-calendar-alt', 'order' => 10];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/programy', function ($request, $response) {
            $event = $this->get(EventConfig::class);
            $auth = $this->get(Authenticator::class);
            $hidden = $event->get('programs')['hiddenNames'] ?? [];
            $sections = $event->sections;
            $notice = null;

            $registeredIds = [];
            if ($auth->isLogged()) {
                try {
                    foreach ($this->get(ProgramProviderInterface::class)->getProgramsForIdentity($auth->identity()) as $program) {
                        $registeredIds[$program['id']] = true;
                    }
                } catch (UnknownParticipantException) {
                    $auth->logout();
                    $notice = 'Váš TIE kód už není platný, byli jste odhlášeni.';
                } catch (\GuzzleHttp\Exception\TransferException) {
                    $notice = 'Osobní program se nepodařilo načíst.';
                }
            }

            try {
                foreach ($this->get(ProgramProviderInterface::class)->getPrograms() as $program) {
                    if (in_array($program['name'], $hidden, true)) {
                        continue;
                    }
                    $sectionId = $program['section']['id'];
                    if (!isset($sections[$sectionId])) {
                        continue; // a program in a section this event does not know — ignore it
                    }
                    $program['multiday'] = date('Y-m-d', strtotime($program['start']['date']))
                        !== date('Y-m-d', strtotime($program['end']['date']));
                    $program['registered'] = isset($registeredIds[$program['id']]);
                    $sections[$sectionId]['programs'][] = $program;
                }
            } catch (\GuzzleHttp\Exception\TransferException) {
                $sections = $event->sections;
                $notice = 'Programy se nepodařilo načíst, zkuste to prosím později.';
            }

            return $this->get(Twig::class)->render($response, 'programs.twig', [
                'sections' => $sections,
                'notice' => $notice,
                'isLogged' => $auth->isLogged(),
                'identity' => $auth->identity()?->displayName,
            ]);
        })->setName('programs');
    }
}
