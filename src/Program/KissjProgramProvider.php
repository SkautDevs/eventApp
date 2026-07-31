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
                    throw new UnknownParticipantException(sprintf('Neznámý TIE kód: %s', $identity->tieCode), previous: $e);
                }

                return []; // přihlášený SkautIS uživatel bez registrace na akci není chyba
            }
            throw $e;
        }

        return array_map($this->normalize(...), $data['programs'] ?? []);
    }

    private function getJson(string $path): array
    {
        $response = $this->http->request('GET', $path);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /** Převod kissj tvaru na interní tvar programu — viz docs/kissj-contract.md */
    private function normalize(array $kissj): array
    {
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

    private function toLocal(?string $iso): string
    {
        if ($iso === null) {
            return '';
        }

        return (new \DateTimeImmutable($iso))
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d H:i:s');
    }
}
