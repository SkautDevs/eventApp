<?php

declare(strict_types=1);

namespace App\Module;

use App\Auth\Authenticator;
use App\EventConfig;
use App\Kernel;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\SessionExpiredException;
use App\Push\MessageRepository;
use App\Push\NewsItems;
use App\Session;
use App\Program\ProgramUnavailableException;
use Slim\App;
use Slim\Views\Twig;

/**
 * Informace (/informace): the event's crisis contact, every notification the organisers
 * sent (not hidden on the admin page) under the day it was sent, newest first, and the
 * quick links with the handbook first. A message for one programme is shown here only to
 * a participant registered for it; everybody reads it in that programme's sheet.
 */
final class InfoModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'info';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Informace', 'route' => 'info', 'icon' => 'fas fa-info-circle', 'order' => 30];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/informace', function ($request, $response) {
            $event = $this->get(EventConfig::class);
            $auth = $this->get(Authenticator::class);
            $messages = $this->get(MessageRepository::class)->visible($event->slug);

            // programme id => its current name, for the participant's own programmes only
            $mine = [];
            $identity = $auth->identity();
            if ($identity !== null) {
                try {
                    foreach ($this->get(ProgramProviderInterface::class)->getParticipant($identity->sessionToken)->programmes as $program) {
                        $mine[(int) $program['id']] = (string) ($program['name'] ?? '');
                    }
                } catch (SessionExpiredException) {
                    // kissj no longer knows the device session: logged out, Profil says why
                    Kernel::sessionGone($auth, $this->get(Session::class));
                    $mine = [];
                } catch (ProgramUnavailableException|ProgramDataException) {
                    // an outage logs nobody out and is not explained here: without the
                    // registrations the list simply shows what everybody sees
                    $mine = [];
                }
            }

            return $this->get(Twig::class)->render($response, 'info.twig', [
                // optional: an event without content/emergency.php shows no contact
                'emergency' => $event->content('emergency'),
                'groups' => NewsItems::groups(NewsItems::items($messages, $mine, new \DateTimeImmutable())),
                'links' => $event->content('links'),
            ]);
        })->setName('info');
    }
}
