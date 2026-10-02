<?php

declare(strict_types=1);

namespace App\Module;

use App\Auth\Authenticator;
use App\Auth\UnknownParticipantException;
use App\EventConfig;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use GuzzleHttp\Exception\TransferException;
use App\Push\MessageRepository;
use Slim\App;
use Slim\Views\Twig;

/**
 * News (/novinky) is the list of the notifications the organisers sent — every message
 * not hidden on the admin page, newest first. A message for one programme is shown here
 * only to a participant registered for it; everybody reads it in that programme's sheet.
 */
final class NewsModule implements ModuleInterface
{
    public static function key(): string
    {
        return 'news';
    }

    public function menuItem(): ?array
    {
        return ['label' => 'Novinky', 'route' => 'news', 'icon' => 'far fa-newspaper', 'order' => 30];
    }

    public function registerRoutes(App $app): void
    {
        $app->get('/novinky', function ($request, $response) {
            $auth = $this->get(Authenticator::class);
            $messages = $this->get(MessageRepository::class)->visible($this->get(EventConfig::class)->slug);

            // programme id => its current name, for the participant's own programmes only
            $mine = [];
            if ($auth->isLogged()) {
                try {
                    foreach ($this->get(ProgramProviderInterface::class)->getProgramsForIdentity($auth->identity()) as $program) {
                        $mine[(int) $program['id']] = (string) ($program['name'] ?? '');
                    }
                } catch (UnknownParticipantException|TransferException|ProgramDataException) {
                    // News does not log anybody out or explain kissj's troubles: without the
                    // registrations it simply shows what everybody sees
                    $mine = [];
                }
            }

            return $this->get(Twig::class)->render($response, 'news.twig', [
                'items' => NewsModule::items($messages, $mine),
            ]);
        })->setName('news');
    }

    /**
     * @param list<array> $messages MessageRepository::visible() rows, newest first
     * @param array<int, string> $mine programme id => name, of the reader's registrations
     * @return list<array{id: int, when: string, title: string, body: string, programme: ?string}>
     */
    public static function items(array $messages, array $mine): array
    {
        $items = [];
        foreach ($messages as $message) {
            $programmeId = $message['programmeId'];
            if ($programmeId !== null && !array_key_exists($programmeId, $mine)) {
                continue;
            }
            $items[] = [
                'id' => $message['id'],
                'when' => self::when($message['sentAt']),
                'title' => $message['title'],
                'body' => $message['body'],
                'programme' => $programmeId === null
                    ? null
                    : ($mine[$programmeId] !== '' ? $mine[$programmeId] : $message['targetLabel']),
            ];
        }

        return $items;
    }

    /** 'Y-m-d H:i:s' as the reader reads it: 'j. n. Y H:i'. */
    public static function when(string $sentAt): string
    {
        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sentAt);

        return $at === false ? $sentAt : $at->format('j. n. Y H:i');
    }
}
