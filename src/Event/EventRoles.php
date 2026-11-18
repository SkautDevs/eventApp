<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's semantic colour roles, the two hand-authored sets `light` and `dark` (role =>
 * CSS value), each in the config's own order, because the layout emits them into the
 * inline `<style>` in that order. A set the config does not declare is `[]`: an event
 * without a dark set is light-only and gets no mode control.
 */
final readonly class EventRoles
{
    /**
     * @param array<string, string> $light
     * @param array<string, string> $dark
     */
    public function __construct(
        public array $light,
        public array $dark,
    ) {}

    /**
     * The sets as the config's `roles` map, mode => roles, with a set that has no roles
     * left out (`[]` for an event that declares none).
     *
     * @return array<string, array<string, string>>
     */
    public function toArray(): array
    {
        return array_filter(['light' => $this->light, 'dark' => $this->dark], static fn(array $set): bool => $set !== []);
    }
}
