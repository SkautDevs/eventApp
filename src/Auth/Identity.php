<?php

declare(strict_types=1);

namespace App\Auth;

final class Identity
{
    public function __construct(
        public readonly string $type,
        public readonly string $displayName,
        public readonly ?int $skautisUserId = null,
        public readonly ?string $tieCode = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'displayName' => $this->displayName,
            'skautisUserId' => $this->skautisUserId,
            'tieCode' => $this->tieCode,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            displayName: $data['displayName'],
            skautisUserId: $data['skautisUserId'] ?? null,
            tieCode: $data['tieCode'] ?? null,
        );
    }
}
