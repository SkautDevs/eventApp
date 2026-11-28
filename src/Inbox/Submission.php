<?php

declare(strict_types=1);

namespace App\Inbox;

/**
 * What the form on /zpravy sent, normalised: the kind (null when missing or unknown), the
 * text and the contact, both trimmed, with every line ending as `\n`. Lengths are counted
 * in characters, not bytes, after that normalisation, so the textarea's `maxlength` and
 * the server agree. The form page renders it back after a failed send.
 */
final readonly class Submission
{
    public const int BODY_MAX = 1000;
    public const int CONTACT_MAX = 100;

    public function __construct(public ?Kind $kind, public string $body, public string $contact) {}

    public static function empty(): self
    {
        return new self(null, '', '');
    }

    /** @param array<array-key, mixed> $fields the parsed body: `kind`, `body`, `contact` */
    public static function fromForm(array $fields): self
    {
        $kind = $fields['kind'] ?? null;

        return new self(
            is_string($kind) ? Kind::tryFrom($kind) : null,
            self::text($fields['body'] ?? null),
            self::text($fields['contact'] ?? null),
        );
    }

    /** @return list<string> Czech sentences, in the spec's order; [] when valid */
    public function errors(): array
    {
        $errors = [];
        if ($this->kind === null) {
            $errors[] = 'Vyber, o co jde.';
        }
        if ($this->body === '') {
            $errors[] = 'Napiš zprávu.';
        } elseif (mb_strlen($this->body) > self::BODY_MAX) {
            $errors[] = sprintf('Zpráva smí mít nejvýš %d znaků.', self::BODY_MAX);
        }
        if (mb_strlen($this->contact) > self::CONTACT_MAX) {
            $errors[] = sprintf('Kontakt smí mít nejvýš %d znaků.', self::CONTACT_MAX);
        }

        return $errors;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';
    }
}
