<?php

declare(strict_types=1);

namespace App\Program;

use App\Telemetry\Tracer;
use App\Time\Zone;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\TransferException;

/**
 * Talks to kissj's programme API, as kissj's API contract 3.0 describes it: the
 * list, the device pairing, the participant behind a device session, the write
 * endpoints and the revoke. Everything that arrives is validated here rather than trusted.
 *
 * Failures: a `401` with an empty body on a call that carries a device session is
 * SessionExpiredException; a `400` with the contract's `{"error": code}` on register or
 * unregister is RegistrationRefusedException; every other non-200, and a kissj that never
 * answers, is a KissjTransferException that names the endpoint in words and chains nothing,
 * so neither a token nor Guzzle's message (which names the URI and quotes the body)
 * reaches logs or Sentry.
 */
final class KissjProgramProvider implements ProgramProviderInterface
{
    /** the contract's header for a device session token, sent beside the API key */
    public const string SESSION_HEADER = 'X-Kissj-Device-Session';

    /**
     * @var array{sections: array<int, Section>, programmes: list<Programme>, registration: RegistrationWindow}|null
     *      the list response, fetched once
     */
    private ?array $list = null;

    /**
     * The key is passed in rather than set as a client default header: it is part of
     * the contract — the event is resolved from it — so the provider owns sending it.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
    ) {}

    public function getPrograms(): array
    {
        return $this->list()['programmes'];
    }

    public function getSections(): array
    {
        return $this->list()['sections'];
    }

    public function getRegistrationWindow(): RegistrationWindow
    {
        return $this->list()['registration'];
    }

    public function getParticipant(string $sessionToken): Participant
    {
        $where = 'the session endpoint';
        $data = $this->request(
            'GET',
            'v3/programme/participant/session',
            [self::SESSION_HEADER => $sessionToken],
            null,
            'kissj.session',
            'GET v3/programme/participant/session',
            $where,
        );

        $participant = $data['participant'] ?? null;
        if (!is_array($participant) || ($participant !== [] && array_is_list($participant))) {
            throw new ProgramDataException(sprintf('kissj sent no participant for %s', $where));
        }
        $id = $participant['id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new ProgramDataException(sprintf('kissj sent a participant without a positive integer id for %s', $where));
        }
        $nickname = $participant['nickname'] ?? null;
        if ($nickname !== null && !is_string($nickname)) {
            throw new ProgramDataException(sprintf('kissj sent a nickname that is not a string for %s', $where));
        }

        // an empty nickname is no nickname: the generic heading, not a blank one
        return new Participant($id, $nickname === '' ? null : $nickname, $this->programmes($data, $where));
    }

    public function getParticipantIdsForProgramme(int $programmeId): array
    {
        $where = 'the programme participants endpoint';
        $ids = $this->request(
            'GET',
            sprintf('v3/programme/%d/participants', $programmeId),
            [],
            null,
            'kissj.programme-participants',
            'GET v3/programme/{id}/participants',
            $where,
        )['participantIds'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new ProgramDataException(sprintf('kissj sent no list of participantIds for %s', $where));
        }
        foreach ($ids as $id) {
            if (!is_int($id)) {
                throw new ProgramDataException(sprintf('kissj sent a participant id that is not an integer for %s', $where));
            }
        }

        return array_values(array_unique($ids));
    }

    public function startPairing(string $deviceId): Pairing
    {
        $where = 'the pairing start endpoint';
        $data = $this->request(
            'POST',
            'v3/programme/pairing/start',
            [],
            ['deviceId' => $deviceId],
            'kissj.pairing-start',
            'POST v3/programme/pairing/start',
            $where,
        );

        $url = self::requiredString($data, 'verificationUrl', $where);
        // the page renders it as a link: only an absolute web address may become one
        if (preg_match('#^https?://[^/\s]#i', $url) !== 1) {
            throw new ProgramDataException(sprintf('kissj sent a verificationUrl that is not an absolute http(s) URL for %s', $where));
        }
        $interval = $data['interval'] ?? null;
        if (!is_int($interval) || $interval < 1) {
            throw new ProgramDataException(sprintf('kissj sent no positive integer interval for %s', $where));
        }

        return new Pairing(
            deviceCode: self::requiredString($data, 'deviceCode', $where),
            userCode: self::requiredString($data, 'userCode', $where),
            verificationUrl: $url,
            expiresAt: self::instant($data['expiresAt'] ?? null, 'expiresAt', $where)
                ?? throw new ProgramDataException(sprintf('kissj sent no expiresAt for %s', $where)),
            interval: $interval,
        );
    }

    public function pollPairing(string $deviceCode): PairingPoll
    {
        $where = 'the pairing poll endpoint';
        $data = $this->request(
            'POST',
            'v3/programme/pairing/poll',
            [],
            ['deviceCode' => $deviceCode],
            'kissj.pairing-poll',
            'POST v3/programme/pairing/poll',
            $where,
        );

        $status = is_string($data['status'] ?? null) ? PairingStatus::tryFrom($data['status']) : null;
        if ($status === null) {
            throw new ProgramDataException(sprintf('kissj sent no known pairing status for %s', $where));
        }
        if ($status !== PairingStatus::Approved) {
            return new PairingPoll($status);
        }

        return new PairingPoll(
            $status,
            self::requiredString($data, 'sessionToken', $where),
            self::instant($data['expiresAt'] ?? null, 'expiresAt', $where)
                ?? throw new ProgramDataException(sprintf('kissj sent an approval without expiresAt for %s', $where)),
        );
    }

    public function register(string $sessionToken, int $programmeId): void
    {
        $this->write('register', $sessionToken, $programmeId);
    }

    public function unregister(string $sessionToken, int $programmeId): void
    {
        $this->write('unregister', $sessionToken, $programmeId);
    }

    public function revokeSession(string $sessionToken): void
    {
        $where = 'the revoke endpoint';
        $data = $this->request(
            'POST',
            'v3/programme/session/revoke',
            [self::SESSION_HEADER => $sessionToken],
            null,
            'kissj.revoke',
            'POST v3/programme/session/revoke',
            $where,
        );
        self::requireOk($data, $where);
    }

    /** @param 'register'|'unregister' $verb */
    private function write(string $verb, string $sessionToken, int $programmeId): void
    {
        $where = sprintf('the %s endpoint', $verb);
        $data = $this->request(
            'POST',
            sprintf('v3/programme/%d/%s', $programmeId, $verb),
            [self::SESSION_HEADER => $sessionToken],
            null,
            'kissj.' . $verb,
            sprintf('POST v3/programme/{id}/%s', $verb),
            $where,
            refusable: true,
        );
        self::requireOk($data, $where);
    }

    /**
     * `programmes`, `sections` and `registration` arrive in one response and a request
     * asks for all of them, so the list is fetched and validated once, as a whole, for
     * the provider's lifetime — which is one request.
     *
     * @return array{sections: array<int, Section>, programmes: list<Programme>, registration: RegistrationWindow}
     */
    private function list(): array
    {
        if ($this->list === null) {
            $where = 'the list endpoint';
            $data = $this->request('GET', 'v3/programme/list', [], null, 'kissj.list', 'GET v3/programme/list', $where);
            if (!array_key_exists('sections', $data)) {
                throw new ProgramDataException(sprintf('kissj sent no sections for %s', $where));
            }
            $this->list = [
                'sections' => Sections::fromKissj($data['sections']),
                'programmes' => $this->programmes($data, $where),
                'registration' => self::registration($data['registration'] ?? null, $where),
            ];
        }

        return $this->list;
    }

    /** @param mixed $raw the list's `registration`, trusted for nothing */
    private static function registration(mixed $raw, string $where): RegistrationWindow
    {
        if (!is_array($raw) || !array_key_exists('start', $raw) || !array_key_exists('end', $raw)) {
            throw new ProgramDataException(sprintf('kissj sent no registration window for %s', $where));
        }

        return new RegistrationWindow(
            self::instant($raw['start'], 'registration start', $where),
            self::instant($raw['end'], 'registration end', $where),
        );
    }

    /**
     * @param array<mixed> $data
     * @param string $where what the error message names: the endpoint, never a path or a token
     *
     * @return list<Programme>
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
     * One call to kissj, with the API key and the caller's headers. Every 200 the contract
     * allows is a JSON object; a body that is not JSON, or is a bare array, is a provider
     * error rather than a 500 on a screen.
     *
     * The span covers the HTTP exchange only, so its status comes from kissj's answer (a
     * 401 is `unauthenticated`, a 400 `invalid_argument`); the answer is turned into this
     * class's exceptions outside it.
     *
     * @param array<string, string>     $headers     beside the API key, e.g. the device session
     * @param array<string, mixed>|null $json        the JSON body; null sends none
     * @param string                    $op          the span op
     * @param string                    $description the span description: the endpoint's shape
     * @param string                    $where       what an error message names: the endpoint, in words
     * @param bool                      $refusable   whether a `400 {"error": code}` is the contract's refusal
     *
     * @return array<mixed> the decoded object: its keys are its names, a numeric name an integer key
     */
    private function request(
        string $method,
        string $path,
        array $headers,
        ?array $json,
        string $op,
        string $description,
        string $where,
        bool $refusable = false,
    ): array {
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'] + $headers,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $body = Tracer::span($op, $description, fn(): string => (string) $this->http->request($method, $path, $options)->getBody());
        } catch (TransferException $e) {
            $response = $e instanceof ResponseException ? $e->getResponse() : null;
            $status = $response?->getStatusCode();
            $answer = $response !== null ? (string) $response->getBody() : '';
            // the contract's "session gone" is an empty 401; a rejected API key is a 401 with
            // kissj's plain text, and taking that for a session would log every reader out
            if ($status === 401 && array_key_exists(self::SESSION_HEADER, $headers) && trim($answer) === '') {
                throw new SessionExpiredException();
            }
            if ($status === 400 && $refusable) {
                $refusal = json_decode($answer, true);
                if (is_array($refusal) && is_string($refusal['error'] ?? null) && $refusal['error'] !== '') {
                    throw new RegistrationRefusedException($refusal['error']);
                }
            }
            throw KissjTransferException::on($where, $status, $e);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ProgramDataException(sprintf('kissj sent something other than a JSON object for %s', $where));
        }

        return $decoded;
    }

    /**
     * A write and the revoke answer `{"ok": true}`; anything else is not the contract.
     *
     * @param array<mixed> $data
     */
    private static function requireOk(array $data, string $where): void
    {
        if (($data['ok'] ?? null) !== true) {
            throw new ProgramDataException(sprintf('kissj sent no {"ok": true} for %s', $where));
        }
    }

    /** @param array<mixed> $data */
    private static function requiredString(array $data, string $key, string $where): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ProgramDataException(sprintf('kissj sent no %s for %s', $key, $where));
        }

        return $value;
    }

    /**
     * A contract datetime, in Europe/Prague; null stays null. The message names the field,
     * never the value: the value could be anything kissj sent.
     */
    private static function instant(mixed $iso, string $field, string $where): ?\DateTimeImmutable
    {
        if ($iso === null) {
            return null;
        }
        if (!is_string($iso)) {
            throw new ProgramDataException(sprintf('kissj sent a %s that is not a datetime string for %s', $field, $where));
        }
        $prague = Zone::prague();
        try {
            return (new \DateTimeImmutable($iso, $prague))->setTimezone($prague);
        } catch (\Exception) {
            throw new ProgramDataException(sprintf('kissj sent an unparseable %s for %s', $field, $where));
        }
    }

    /**
     * Maps one kissj programme onto a Programme — see kissj's API contract 3.0.
     * Fields the contract does not map (`targetRoles`, whatever comes later) are ignored.
     *
     * @param mixed $kissj one element of whatever kissj sent, trusted for nothing
     */
    private function normalize(mixed $kissj): Programme
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
        $preregistered = $kissj['isPreregistered'] ?? false;
        if (!is_bool($preregistered)) {
            throw new ProgramDataException('kissj program record has an isPreregistered that is not a boolean');
        }

        return new Programme(
            id: (int) $kissj['id'],
            name: (string) $kissj['name'],
            sectionId: $kissj['sectionId'],
            start: $this->toLocal($kissj['start'] ?? null),
            end: $this->toLocal($kissj['end'] ?? null),
            lector: null,
            location: $this->optionalText($kissj['place'] ?? null),
            perex: $this->optionalText($kissj['description'] ?? null),
            tools: null,
            preregistered: $preregistered,
        );
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

    /** A programme's time in Europe/Prague, to the second; a missing or empty one is null. */
    private function toLocal(mixed $iso): ?\DateTimeImmutable
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        if (!is_string($iso)) {
            throw new ProgramDataException('kissj sent a datetime that is not a string');
        }

        $prague = Zone::prague();
        try {
            // the zone is the fallback for a naive datetime, which is what a PHP app most
            // likely emits — a no-op when the string carries an offset of its own
            $parsed = new \DateTimeImmutable($iso, $prague);
        } catch (\Exception $e) {
            throw new ProgramDataException(sprintf('kissj sent an unparseable datetime: %s', $iso), previous: $e);
        }

        // whole seconds, as the stored row (Programme::toArray()) keeps it
        return (new \DateTimeImmutable('@' . $parsed->getTimestamp()))->setTimezone($prague);
    }
}
