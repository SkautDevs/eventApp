<?php

declare(strict_types=1);

namespace App;

/** Every event under events/, for the front door and the picker. */
final class EventCatalog
{
    public function __construct(private readonly string $eventsDir)
    {
    }

    public function has(string $slug): bool
    {
        return preg_match('/^[a-z0-9-]+\z/', $slug) === 1
            && is_file($this->eventsDir . '/' . $slug . '/config.php');
    }

    public function load(string $slug): EventConfig
    {
        return EventConfig::load($this->eventsDir, $slug);
    }

    /**
     * Listed events, split at today: upcoming or ongoing (end >= today) soonest first,
     * then past, most recent first.
     *
     * @return array{upcoming: list<EventConfig>, past: list<EventConfig>}
     */
    public function listed(\DateTimeImmutable $today): array
    {
        $day = $today->format('Y-m-d');
        $upcoming = $past = [];
        foreach (glob($this->eventsDir . '/*/config.php') ?: [] as $file) {
            $event = $this->load(basename(dirname($file)));
            if (!$event->listed) {
                continue;
            }
            if ($event->dates['end'] >= $day) {
                $upcoming[] = $event;
            } else {
                $past[] = $event;
            }
        }
        usort($upcoming, static fn ($a, $b) => $a->dates['start'] <=> $b->dates['start']);
        usort($past, static fn ($a, $b) => $b->dates['start'] <=> $a->dates['start']);

        return ['upcoming' => $upcoming, 'past' => $past];
    }
}
