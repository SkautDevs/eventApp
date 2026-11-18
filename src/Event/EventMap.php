<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's `map`: the Google My Maps embed (`embedUrl`, which may still carry the
 * REPLACE-ME placeholder), the self-hosted site plan (`image`, a path under www/) and its
 * alt text (`imageAlt`). Each is kept as the config gives it; an undeclared one is null.
 * What counts as "no map" is the Místa screen's and the CSP's decision.
 */
final readonly class EventMap
{
    public function __construct(
        public ?string $embedUrl,
        public ?string $image,
        public ?string $imageAlt,
    ) {}

    /**
     * The config's `map` map. A value that is not a string counts as undeclared.
     *
     * @param array<mixed> $map
     */
    public static function fromArray(array $map): self
    {
        $string = static fn(string $key): ?string => is_string($map[$key] ?? null) ? $map[$key] : null;

        return new self($string('embedUrl'), $string('image'), $string('imageAlt'));
    }
}
