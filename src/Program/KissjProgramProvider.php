<?php

declare(strict_types=1);

namespace App\Program;

use App\Auth\Identity;
use App\Auth\UnknownParticipantException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

/**
 * Reads the Program screen's data from kissj's programme API, as agreed in
 * docs/kissj-contract.md. The contract is agreed but kissj does not serve it yet, so
 * everything that arrives is validated here rather than trusted.
 */
final class KissjProgramProvider implements ProgramProviderInterface
{
    /** @var array{sections: array<int, array>, programmes: list<array>}|null the list response, fetched once */
    private ?array $list = null;

    /**
     * The key is passed in rather than set as a client default header: it is part of
     * the contract — the event is resolved from it — so the provider owns sending it.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
    ) {
    }

    public function getPrograms(): array
    {
        return $this->list()['programmes'];
    }

    public function getSections(): array
    {
        return $this->list()['sections'];
    }

    public function getProgramsForIdentity(Identity $identity): array
    {
        $path = sprintf('v3/programme/participant/tie/%s', rawurlencode($identity->tieCode));

        // the path carries the code, so the messages below name the endpoint instead
        $where = 'the participant endpoint';

        try {
            $data = $this->getJson($path, 'kissj.participant', 'GET v3/programme/participant/tie', $where);
        } catch (TransferException $e) {
            $status = $e instanceof RequestException ? $e->getResponse()?->getStatusCode() : null;
            if ($status === 404) {
                // the code stays out of the message and out of the chain: both reach logs and Sentry
                throw new UnknownParticipantException('Unknown TIE code');
            }
            throw KissjTransferException::on($where, $status, $e);
        }

        return $this->programmes($data, $where);
    }

    public function getTieCodesForProgramme(int $programmeId): array
    {
        $path = sprintf('v3/programme/%d/participants', $programmeId);
        $codes = $this->getJson($path, 'kissj.programme-participants', 'GET v3/programme/{id}/participants')['tieCodes'] ?? null;
        if (!is_array($codes) || !array_is_list($codes)) {
            throw new ProgramDataException(sprintf('kissj sent no list of tieCodes for %s', $path));
        }
        foreach ($codes as $code) {
            if (!is_string($code) || $code === '') {
                throw new ProgramDataException(sprintf('kissj sent a TIE code that is not a string for %s', $path));
            }
        }

        return array_values(array_unique(array_map('strtoupper', $codes)));
    }

    /**
     * `programmes` and `sections` arrive in one response and a request asks for both,
     * so the list is fetched and validated once, as a whole, for the provider's lifetime
     * — which is one request.
     *
     * @return array{sections: array<int, array>, programmes: list<array>}
     */
    private function list(): array
    {
        if ($this->list === null) {
            $path = 'v3/programme/list';
            $data = $this->getJson($path, 'kissj.list', 'GET v3/programme/list');
            if (!array_key_exists('sections', $data)) {
                throw new ProgramDataException(sprintf('kissj sent no sections for %s', $path));
            }
            $this->list = [
                'sections' => Sections::fromKissj($data['sections']),
                'programmes' => $this->programmes($data, $path),
            ];
        }

        return $this->list;
    }

    /**
     * @param string $where what the error message names: the path, or the endpoint when the path carries a TIE code
     *
     * @return list<array>
     */
    private function programmes(array $data, string $where): array
    {
        $programmes = $data['programmes'] ?? null;
        if (!is_array($programmes) || !array_is_list($programmes)) {
            throw new ProgramDataException(sprintf('kissj sent no list of programmes for %s', $where));
        }

        return array_map($this->normalize(...), $programmes);
    }

    /**
     * Every 200 the contract allows is a JSON object. A body that is not JSON, or is a
     * bare array, is a provider error rather than a 500 on /programy. Any status other
     * than 200 — a 401 for a rejected key included — surfaces as Guzzle's own exception.
     *
     * @param string      $op          the span op, named by the caller
     * @param string      $description the span description: the endpoint's shape, never a TIE code
     * @param string|null $where       what an error message names instead of the path — set
     *                                 whenever the path carries a TIE code
     *
     * @return array<string, mixed>
     */
    private function getJson(string $path, string $op, string $description, ?string $where = null): array
    {
        return \App\Telemetry\Tracer::span($op, $description, function () use ($path, $where): array {
            $response = $this->http->request('GET', $path, [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
            ]);

            $decoded = json_decode((string) $response->getBody(), true);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                throw new ProgramDataException(sprintf('kissj sent something other than a JSON object for %s', $where ?? $path));
            }

            return $decoded;
        });
    }

    /**
     * Maps one kissj programme onto our internal program shape — see docs/kissj-contract.md.
     * Fields the contract does not name (`isPreregistered`, `targetRoles`, whatever comes
     * later) are ignored.
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
        // strict: the id has to match a section's integer id, and a coerced one could
        // quietly match the wrong section
        if (!isset($kissj['sectionId']) || !is_int($kissj['sectionId'])) {
            throw new ProgramDataException('kissj program record has no integer sectionId');
        }

        return [
            'id' => (int) $kissj['id'],
            'name' => (string) $kissj['name'],
            'section' => ['id' => $kissj['sectionId']],
            'start' => ['date' => $this->toLocal($kissj['start'] ?? null)],
            'end' => ['date' => $this->toLocal($kissj['end'] ?? null)],
            'lector' => null,
            'location' => $this->optionalText($kissj['place'] ?? null),
            'perex' => $this->optionalText($kissj['description'] ?? null),
            'tools' => null,
        ];
    }

    /** kissj sends an empty string for "none"; the screen tests for null. */
    private function optionalText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new ProgramDataException('kissj sent a text field that is not a string');
        }

        return (string) $value;
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
