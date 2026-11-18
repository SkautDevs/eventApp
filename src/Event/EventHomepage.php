<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's `homepage`: Domů's colophon, the footer logo (a path under www/) and the line
 * `footerText` followed by the link `footerLinkLabel` to `footerLinkHref`. Each is kept as
 * the config gives it, `''` included; an undeclared one is null.
 */
final readonly class EventHomepage
{
    public function __construct(
        public ?string $footerLogo,
        public ?string $footerText,
        public ?string $footerLinkLabel,
        public ?string $footerLinkHref,
    ) {}

    /**
     * The config's `homepage` map. A value that is not a string counts as undeclared.
     *
     * @param array<mixed> $homepage
     */
    public static function fromArray(array $homepage): self
    {
        $string = static fn(string $key): ?string => is_string($homepage[$key] ?? null) ? $homepage[$key] : null;

        return new self($string('footerLogo'), $string('footerText'), $string('footerLinkLabel'), $string('footerLinkHref'));
    }
}
