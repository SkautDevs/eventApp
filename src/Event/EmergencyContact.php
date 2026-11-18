<?php

declare(strict_types=1);

namespace App\Event;

/** One crisis contact of an event's `content/emergency.php`: who, the number, and an optional note. */
final readonly class EmergencyContact
{
    public function __construct(
        public string $label,
        public string $phone,
        public ?string $note,
    ) {}

    /**
     * One row of `contacts`. Lenient: a missing label or phone is `''`, a missing note null.
     *
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $label = $row['label'] ?? null;
        $phone = $row['phone'] ?? null;
        $note = $row['note'] ?? null;

        return new self(is_string($label) ? $label : '', is_string($phone) ? $phone : '', is_string($note) ? $note : null);
    }
}
