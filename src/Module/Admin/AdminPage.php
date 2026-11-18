<?php

declare(strict_types=1);

namespace App\Module\Admin;

use App\EventConfig;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\ProgramUnavailableException;
use App\Push\MessageRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The admin page as the three admin actions render it: the send form (with a fresh one-time
 * nonce), the result a send flashed, the programme picker grouped by day, and the message
 * log fifty to a page; and the 403 page of a refused POST, which hands back what was typed.
 */
final class AdminPage
{
    /** The message log's page size. */
    private const int LOG_PAGE = 50;

    public const string SIGNATURE_ERROR = 'Podpis je povinný a smí mít nejvýš 40 znaků.';

    public function __construct(
        private readonly EventConfig $event,
        private readonly AdminSession $admin,
        private readonly MessageRepository $messages,
        private readonly ProgramProviderInterface $provider,
        private readonly Twig $twig,
    ) {}

    /** @param array<string, mixed> $context what the caller states over the defaults */
    public function render(ServerRequestInterface $request, ResponseInterface $response, array $context = []): ResponseInterface
    {
        $slug = $this->event->slug;
        $params = $request->getMethod() === 'GET' ? $request->getQueryParams() : (array) $request->getParsedBody();
        $page = max(1, (int) ($params['page'] ?? 1));
        [$programmes, $unavailable] = $this->programmes();

        // the result of a send is flashed by the POST and shown once, on the GET it redirects to
        $flash = $request->getMethod() === 'GET' ? $this->admin->takeResult() : null;

        $nonce = $this->admin->issueNonce();

        return $this->twig->render($response, 'admin-notify.twig', $context + [
            'csrf' => $this->admin->csrf(),
            'nonce' => $nonce,
            'notice' => null,
            'result' => $flash['result'] ?? null,
            'errors' => [],
            'values' => ['target' => '', 'title' => '', 'body' => '', 'signature' => $flash['signature'] ?? ''],
            'groups' => self::programmeOptions($programmes),
            'programmesUnavailable' => $unavailable,
            'log' => $this->messages->page($slug, $page, self::LOG_PAGE),
            'page' => $page,
            'hasOlder' => $this->messages->count($slug) > $page * self::LOG_PAGE,
        ]);
    }

    /**
     * A refused POST (the day is up, or the CSRF token no longer matches) says so in words
     * and hands back what was typed, because Back cannot: the admin page is no-store, and
     * its GET is refused for the same reason. The hide/show forms carry no text to return.
     */
    public function refused(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $echo = [];
        foreach (['title', 'body'] as $field) {
            if (is_string($body[$field] ?? null) && trim($body[$field]) !== '') {
                $echo[$field] = $body[$field];
            }
        }

        return $this->twig->render($response->withStatus(403), 'admin-expired.twig', ['message' => $echo]);
    }

    /**
     * The event's programmes for the picker; a provider outage leaves only "everyone".
     *
     * @return array{0: list<array>, 1: bool} the programmes, and whether they could not be read
     */
    public function programmes(): array
    {
        try {
            return [$this->provider->getPrograms(), false];
        } catch (ProgramUnavailableException|ProgramDataException) {
            return [[], true];
        }
    }

    /** Podpis: required, at most 40 characters. */
    public static function signatureValid(string $signature): bool
    {
        return $signature !== '' && mb_strlen($signature) <= 40;
    }

    /**
     * One group per calendar day, programmes in start order. The group carries its first
     * programme's start so the template can name the day with the dateToCzechDayName filter.
     *
     * @return list<array{start: array, label: string, options: list<array{id: int, label: string}>}>
     */
    private static function programmeOptions(array $programs): array
    {
        usort($programs, fn(array $a, array $b): int => [$a['start']['date'], $a['id']] <=> [$b['start']['date'], $b['id']]);

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
}
