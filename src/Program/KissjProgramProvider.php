<?php

declare(strict_types=1);

namespace App\Program;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

final class KissjProgramProvider implements ProgramProviderInterface
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $eventSlug,
    ) {
    }

    public function getPrograms(): array
    {
        $data = $this->getJson(sprintf('events/%s/programs', $this->eventSlug));

        return array_map($this->normalize(...), $data);
    }

    public function getProgramsForIdentity(Identity $identity): array
    {
        $path = $identity->type === 'tie'
            ? sprintf('events/%s/participants/tie/%s/programs', $this->eventSlug, rawurlencode((string) $identity->tieCode))
            : sprintf('events/%s/participants/skautis/%d/programs', $this->eventSlug, $identity->skautisUserId);

        try {
            $data = $this->getJson($path);
        } catch (RequestException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                if ($identity->type === 'tie') {
                    throw new UnknownParticipantException(sprintf('Unknown TIE code: %s', $identity->tieCode), previous: $e);
                }

                return []; // a logged-in SkautIS user with no registration for the event is not an error
            }
            throw $e;
        }

        $programs = $data['programs'] ?? [];
        if (!is_array($programs)) {
            throw new ProgramDataException('kissj returned a non-list of programs for a participant');
        }

        return array_map($this->normalize(...), $programs);
    }

    /**
     * kissj is an unverified boundary — see docs/kissj-contract.md, whose own title says
     * so. A body that is not JSON, or is JSON but not a list of records, is a provider
     * error rather than a 500 on /programy.
     */
    private function getJson(string $path): array
    {
        $response = $this->http->request('GET', $path);
        $body = (string) $response->getBody();
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ProgramDataException(sprintf('kissj returned a non-array payload for %s', $path));
        }

        return $decoded;
    }

    /**
     * Maps the kissj shape onto our internal program shape — see docs/kissj-contract.md.
     *
     * @param mixed $kissj one element of whatever kissj sent, trusted for nothing
     */
    private function normalize(mixed $kissj): array
    {
        if (!is_array($kissj)) {
            throw new ProgramDataException('kissj program record is not an object');
        }
        foreach (['id', 'name'] as $required) {
            if (!isset($kissj[$required]) || !is_scalar($kissj[$required])) {
                throw new ProgramDataException(sprintf('kissj program record has no usable %s', $required));
            }
        }

        return [
            'id' => (int) $kissj['id'],
            'name' => (string) $kissj['name'],
            'section' => ['id' => (int) ($kissj['sectionId'] ?? 0)],
            'start' => ['date' => $this->toLocal($kissj['start'] ?? null)],
            'end' => ['date' => $this->toLocal($kissj['end'] ?? null)],
            'lector' => $kissj['lector'] ?? null,
            'location' => $kissj['location'] ?? null,
            'perex' => $kissj['description'] ?? null,
            'tools' => $kissj['tools'] ?? null,
        ];
    }

    private function toLocal(mixed $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        if (!is_string($iso)) {
            throw new ProgramDataException('kissj sent a datetime that is not a string');
        }

        $prague = new \DateTimeZone('Europe/Prague');
        try {
            // the zone is the fallback for a naive datetime, which is what a PHP app most
            // likely emits — a no-op when the string carries an offset of its own
            $parsed = new \DateTimeImmutable($iso, $prague);
        } catch (\Exception $e) {
            throw new ProgramDataException(sprintf('kissj sent an unparseable datetime: %s', $iso), previous: $e);
        }

        return $parsed->setTimezone($prague)->format('Y-m-d H:i:s');
    }
}
