<?php

declare(strict_types=1);

namespace App\Module\Admin;

use App\EventConfig;
use App\Http\FormField;
use App\Inbox\InboxMessage;
use App\Inbox\InboxRepository;
use App\Inbox\Kind;
use App\Module\Admin\View\AdminPage;
use App\Module\Admin\View\FormValues;
use App\Module\Admin\View\InboxSection;
use App\Module\Admin\View\NotifyResult;
use App\Module\Admin\View\ProgrammeGroup;
use App\Module\Admin\View\ProgrammeOption;
use App\Program\ProgramDataException;
use App\Program\ProgramProviderInterface;
use App\Program\Programme;
use App\Program\ProgramUnavailableException;
use App\Push\MessageRepository;
use App\Time\Zone;
use App\View\TemplateContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The admin page as the admin actions render it (View\AdminPage): the send form (with a
 * fresh one-time nonce), the result a send flashed, the programme picker grouped by day,
 * the participants' messages for an event with `inbox` (View\InboxSection), and the
 * message log fifty to a page; and the 403 page of a refused POST, which hands back what
 * was typed.
 */
final class AdminPageRenderer
{
    /** The message log's page size. */
    private const int LOG_PAGE = 50;

    /** The page size of the handled participants' messages, paged by `inbox`. */
    public const int INBOX_PAGE = 20;

    public const string SIGNATURE_ERROR = 'Podpis je povinný a smí mít nejvýš 40 znaků.';

    public function __construct(
        private readonly EventConfig $event,
        private readonly AdminSession $admin,
        private readonly MessageRepository $messages,
        private readonly InboxRepository $inbox,
        private readonly ProgramProviderInterface $provider,
        private readonly Twig $twig,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * The admin page; a caller states the errors, the values typed and a notice, a fresh
     * form has none of them and keeps only the Podpis a send flashed.
     *
     * @param list<string> $errors
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $errors = [],
        ?FormValues $values = null,
        ?string $notice = null,
    ): ResponseInterface {
        $slug = $this->event->slug;
        $params = $request->getMethod() === 'GET' ? $request->getQueryParams() : (array) $request->getParsedBody();
        $page = max(1, FormField::int($params, 'page', 1));
        [$programmes, $unavailable] = $this->programmes();

        // the result of a send is flashed by the POST and shown once, on the GET it redirects to
        $flash = $request->getMethod() === 'GET' ? $this->admin->takeResult() : null;
        $result = $flash['result'] ?? null;

        $nonce = $this->admin->issueNonce();

        $view = new AdminPage(
            csrf: $this->admin->csrf(),
            nonce: $nonce,
            notice: $notice,
            result: is_array($result) ? NotifyResult::fromArray($result) : null,
            errors: $errors,
            values: $values ?? new FormValues('', '', '', $flash === null ? '' : FormField::text($flash, 'signature')),
            groups: self::programmeOptions($programmes, $this->clock->now()->setTimezone(Zone::prague())),
            programmesUnavailable: $unavailable,
            log: $this->messages->page($slug, $page, self::LOG_PAGE),
            page: $page,
            hasOlder: $this->messages->count($slug) > $page * self::LOG_PAGE,
            inbox: $this->event->isEnabled('inbox') ? $this->inboxSection($params) : null,
        );

        return $this->twig->render($response, 'admin-notify.twig', TemplateContext::of($view));
    }

    /**
     * A refused POST (the day is up, or the CSRF token no longer matches) says so in words
     * and hands back what was typed, because Back cannot: the admin page is no-store, and
     * its GET is refused for the same reason. The hide/show forms and the inbox's forms carry
     * no text to return.
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
     * @return array{0: list<Programme>, 1: bool} the programmes, and whether they could not be read
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
     * The participants' messages: every unhandled one by kind, and the page of handled ones
     * that `inbox` names (at most the last page there is), which starts open when the
     * parameter was given.
     *
     * @param array<mixed> $params the request's query, or a re-rendered form's fields
     */
    private function inboxSection(array $params): InboxSection
    {
        $slug = $this->event->slug;
        $unhandled = $this->inbox->unhandled($slug);
        $handledCount = $this->inbox->countHandled($slug);
        // an action keeps `inbox`, so deleting or returning the last message of the last page
        // would land on a page past the end: the last page that exists is shown instead
        $lastPage = max(1, intdiv($handledCount + self::INBOX_PAGE - 1, self::INBOX_PAGE));
        $page = min(max(1, FormField::int($params, 'inbox', 1)), $lastPage);
        $ofKind = static fn(Kind $kind): array => array_values(
            array_filter($unhandled, static fn(InboxMessage $message): bool => $message->kind === $kind),
        );

        return new InboxSection(
            help: $ofKind(Kind::Help),
            ideas: $ofKind(Kind::Idea),
            unhandled: count($unhandled),
            handled: $this->inbox->handledPage($slug, $page, self::INBOX_PAGE),
            handledCount: $handledCount,
            handledPage: $page,
            hasOlderHandled: $handledCount > $page * self::INBOX_PAGE,
            open: array_key_exists('inbox', $params),
        );
    }

    /**
     * One group per calendar day, programmes in start order — compared as the stored
     * `Y-m-d H:i:s` strings, so a programme without a start ('') comes first, and listed
     * under $now's day at $now's time. The group carries its first programme's start so
     * the template can name the day with the dateToCzechDayName filter. The place follows
     * a dash when there is one, untrimmed.
     *
     * @param list<Programme> $programs
     * @return list<ProgrammeGroup>
     */
    private static function programmeOptions(array $programs, \DateTimeImmutable $now): array
    {
        $order = static fn(Programme $programme): array => [$programme->start?->format('Y-m-d H:i:s') ?? '', $programme->id];
        usort($programs, static fn(Programme $a, Programme $b): int => $order($a) <=> $order($b));

        $starts = [];
        $options = [];
        foreach ($programs as $programme) {
            $start = $programme->start ?? $now;
            $day = $start->format('Y-m-d');
            $starts[$day] ??= $start;
            $options[$day][] = new ProgrammeOption(
                $programme->id,
                $start->format('H:i') . ' ' . $programme->name
                    . ($programme->location !== null ? ' – ' . $programme->location : ''),
            );
        }

        $groups = [];
        foreach ($starts as $day => $start) {
            $groups[] = new ProgrammeGroup($start, $start->format('j. n.'), $options[$day]);
        }

        return $groups;
    }
}
