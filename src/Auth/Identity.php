<?php

declare(strict_types=1);

namespace App\Auth;

final class Identity
{
    public function __construct(
        public readonly string $displayName,
        public readonly string $tieCode,
    ) {
    }

    public function toArray(): array
    {
        return [
            'displayName' => $this->displayName,
            'tieCode' => $this->tieCode,
        ];
    }

    /** Null for anything that is not a TIE identity. */
    public static function fromArray(array $data): ?self
    {
        return is_string($data['tieCode'] ?? null) && $data['tieCode'] !== '' && is_string($data['displayName'] ?? null)
            ? new self(displayName: $data['displayName'], tieCode: $data['tieCode'])
            : null;
    }
}
