<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's `map`: the Google My Maps embed (`embedUrl`, which may still carry the
 * REPLACE-ME placeholder), the self-hosted site plan (`image`, a path under www/), its
 * alt text (`imageAlt`) and the plan's edges in degrees (`planBounds`), which place a
 * place with GPS on the plan. Each string is kept as the config gives it; an undeclared
 * one is null. usableEmbedUrl() says when the embed counts as a map.
 */
final readonly class EventMap
{
    public function __construct(
        public ?string $embedUrl,
        public ?string $image,
        public ?string $imageAlt,
        public ?PlanBounds $planBounds = null,
    ) {}

    /**
     * The config's `map` map. A value that is not a string counts as undeclared,
     * and so do bounds that PlanBounds::fromArray() rejects (EventConfig refuses those).
     *
     * @param array<mixed> $map
     */
    public static function fromArray(array $map): self
    {
        $string = static fn(string $key): ?string => is_string($map[$key] ?? null) ? $map[$key] : null;

        return new self($string('embedUrl'), $string('image'), $string('imageAlt'), PlanBounds::fromArray($map['planBounds'] ?? null));
    }

    /**
     * The embed, unless there is none: unset, empty, or still the REPLACE-ME placeholder,
     * which would embed a Google 404 — the organisers have not published the map yet.
     */
    public function usableEmbedUrl(): ?string
    {
        $embedUrl = $this->embedUrl;

        return $embedUrl === null || $embedUrl === '' || str_contains($embedUrl, 'REPLACE-ME') ? null : $embedUrl;
    }
}
