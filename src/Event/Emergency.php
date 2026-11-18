<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's `content/emergency.php`, Informace's crisis contact: the `contacts` (each
 * with a `Zavolat` button) and the `info` lines under them, each `[]` when the file has
 * none.
 */
final readonly class Emergency
{
    /**
     * @param list<EmergencyContact> $contacts
     * @param list<string> $info
     */
    public function __construct(
        public array $contacts,
        public array $info,
    ) {}

    /** @param array<mixed> $content the array the file returns */
    public static function fromArray(array $content): self
    {
        $contacts = [];
        foreach (is_array($content['contacts'] ?? null) ? $content['contacts'] : [] as $row) {
            if (is_array($row)) {
                $contacts[] = EmergencyContact::fromArray($row);
            }
        }
        $info = [];
        foreach (is_array($content['info'] ?? null) ? $content['info'] : [] as $line) {
            if (is_string($line)) {
                $info[] = $line;
            }
        }

        return new self($contacts, $info);
    }
}
