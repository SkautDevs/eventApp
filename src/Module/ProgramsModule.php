<?php

declare(strict_types=1);

namespace App\Module;

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
        return ['label' => 'Programy', 'route' => 'programs'];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/programy', function ($request, $response) {
            $event = $this->get(EventConfig::class);
            $hidden = $event->get('programs')['hiddenNames'] ?? [];
            $sections = $event->sections;

            foreach ($this->get(ProgramProviderInterface::class)->getPrograms() as $program) {
                if (in_array($program['name'], $hidden, true)) {
                    continue;
                }
                $sectionId = $program['section']['id'];
                if (!isset($sections[$sectionId])) {
                    continue; // program v sekci, kterou akce nezná — ignorovat
                }
                $program['multiday'] = date('Y-m-d', strtotime($program['start']['date']))
                    !== date('Y-m-d', strtotime($program['end']['date']));
                $sections[$sectionId]['programs'][] = $program;
            }

            return $this->get(Twig::class)->render($response, 'programs.twig', ['sections' => $sections]);
        })->setName('programs');
    }
}
