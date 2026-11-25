<?php

declare(strict_types=1);

namespace App\Module\Profile;

/**
 * One check of the pending pairing (PairingCheck::run()): the contract's status, and, for
 * "approved" only, where the reader lands, relative to the event's base path
 * (`programy/moje`, `programy#section-1-program-1`, `profil`: PairingCheck::landing()).
 */
final readonly class PairingAnswer
{
    public function __construct(
        public string $status,
        public ?string $next = null,
    ) {}

    /**
     * The poll's JSON body: `status`, plus `next` only when there is one.
     *
     * @return array{status: string, next?: string}
     */
    public function toArray(): array
    {
        $answer = ['status' => $this->status];
        if ($this->next !== null) {
            $answer['next'] = $this->next;
        }

        return $answer;
    }
}
