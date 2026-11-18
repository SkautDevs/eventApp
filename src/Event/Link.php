<?php

declare(strict_types=1);

namespace App\Event;

/**
 * One quick link of an event's `content/links.php`: the `label` with an optional `suffix`
 * after it (a phone number) and `note` under it, the `href` (an absolute URL opens in a new
 * tab), a Font Awesome `icon`, and `highlight`, which makes it tonal on Informace and puts
 * it on Domů as a CTA.
 */
final readonly class Link
{
    public function __construct(
        public string $label,
        public string $href,
        public ?string $icon,
        public ?string $suffix,
        public bool $highlight,
        public ?string $note,
    ) {}

    /**
     * One row of the file. Lenient: a missing label or href is `''`, a missing or
     * non-string optional field null, and `highlight` counts when truthy.
     *
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $string = static fn(string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;

        return new self(
            label: $string('label') ?? '',
            href: $string('href') ?? '',
            icon: $string('icon'),
            suffix: $string('suffix'),
            highlight: (bool) ($row['highlight'] ?? false),
            note: $string('note'),
        );
    }
}
