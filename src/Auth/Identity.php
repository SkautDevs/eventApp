<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * A paired participant: kissj's participant id, the nickname kissj gave for it, and the
 * device session token that every personal call carries. The token lives in the
 * server-side session only and never reaches a template or the reader's JavaScript.
 */
final readonly class Identity
{
    public function __construct(
        public int $participantId,
        public ?string $nickname,
        public string $sessionToken,
    ) {
    }

    /** The nickname, or a generic heading when kissj has none. */
    public function displayName(): string
    {
        return $this->name() ?? 'Účastník';
    }

    /**
     * Two letters for the avatar: the first letters of the first and the last word, or
     * the first two letters of a single word (one letter if that is all there is); null
     * without a nickname, which shows the generic icon instead.
     */
    public function initials(): ?string
    {
        $name = $this->name();
        if ($name === null) {
            return null;
        }
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
        $initials = count($words) === 1
            ? mb_substr($words[0], 0, 2)
            : mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1);

        return mb_strtoupper($initials);
    }

    /** @return array{participantId: int, nickname: ?string, sessionToken: string} */
    public function toArray(): array
    {
        return [
            'participantId' => $this->participantId,
            'nickname' => $this->nickname,
            'sessionToken' => $this->sessionToken,
        ];
    }

    /** Null for anything that is not a paired identity, one stored before pairing included. */
    public static function fromArray(array $data): ?self
    {
        $participantId = $data['participantId'] ?? null;
        $sessionToken = $data['sessionToken'] ?? null;
        if (!is_int($participantId) || $participantId < 1 || !is_string($sessionToken) || $sessionToken === '') {
            return null;
        }
        $nickname = $data['nickname'] ?? null;

        return new self($participantId, is_string($nickname) ? $nickname : null, $sessionToken);
    }

    private function name(): ?string
    {
        $name = $this->nickname === null ? '' : trim($this->nickname);

        return $name === '' ? null : $name;
    }
}
