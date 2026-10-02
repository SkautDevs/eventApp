<?php

declare(strict_types=1);

namespace App;

final class EventConfig
{
    /**
     * The shared www/style.css deliberately carries no colour fallbacks, so a missing
     * key does not degrade — it renders as `unset`. Fail at boot instead of shipping
     * an event whose headings inherit the body colour.
     */
    private const REQUIRED_COLORS = ['background', 'link', 'base', 'darker', 'primary', 'text', 'text-invert'];

    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly array $colors,
        /**
         * Optional look-and-feel overrides, emitted as --theme-* custom properties.
         * Unlike $colors this is deliberately unvalidated and may be empty: every rule
         * in www/style.css that reads one carries today's value as its var() fallback,
         * so an event that declares no theme renders exactly as it did before the map
         * existed. Values are raw CSS and may reference the palette, e.g.
         * 'ruler-bg' => 'var(--color-primary)'.
         */
        public readonly array $theme,
        /**
         * Semantic colour roles, as ['light' => [...], 'dark' => [...]]. Optional and
         * unvalidated like $theme: www/style.css falls every role back to the palette
         * expression its rules used before roles existed, so an event that declares
         * none renders exactly as it did. An event without a 'dark' set is light-only
         * and its layout shows no mode toggle.
         */
        public readonly array $roles,
        public readonly array $features,
        /** Shown in the picker at `/`. An unlisted event still works at its URL. */
        public readonly bool $listed,
        /** @var array{start: string, end: string}|null  Y-m-d, both inclusive */
        public readonly ?array $dates,
        public readonly array $raw,
        public readonly string $dir,
    ) {
    }

    public static function load(string $eventsDir, string $slug): self
    {
        if (preg_match('/^[a-z0-9-]+\z/', $slug) !== 1) {
            throw new \RuntimeException(sprintf('Invalid event slug: "%s"', $slug));
        }

        $file = $eventsDir . '/' . $slug . '/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Unknown event: "%s" (%s not found)', $slug, $file));
        }

        $data = require $file;
        foreach (['name', 'colors', 'features'] as $key) {
            if (!isset($data[$key])) {
                throw new \RuntimeException(sprintf('Event config "%s" is missing the key "%s"', $slug, $key));
            }
        }

        if (in_array('news', $data['features'], true) && !in_array('push', $data['features'], true)) {
            throw new \RuntimeException(sprintf('Event config "%s" enables news without push: News lists the sent notifications', $slug));
        }

        foreach (self::REQUIRED_COLORS as $color) {
            if (!isset($data['colors'][$color])) {
                throw new \RuntimeException(sprintf('Event config "%s" is missing the colour "%s"', $slug, $color));
            }
        }

        $listed = (bool) ($data['listed'] ?? false);
        $dates = $data['dates'] ?? null;
        if ($dates !== null) {
            if (!is_array($dates)) {
                throw new \RuntimeException(sprintf('Event config "%s" has invalid dates (expected start and end)', $slug));
            }
            foreach (['start', 'end'] as $k) {
                $parsed = is_string($dates[$k] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $dates[$k]) : false;
                // the round trip rejects dates PHP would roll over, e.g. 2025-02-31
                if ($parsed === false || $parsed->format('Y-m-d') !== $dates[$k]) {
                    throw new \RuntimeException(sprintf('Event config "%s" has an invalid dates.%s (expected Y-m-d)', $slug, $k));
                }
            }
            if ($dates['end'] < $dates['start']) {
                throw new \RuntimeException(sprintf('Event config "%s" has dates.end before dates.start', $slug));
            }
        }
        // the picker orders by date, so a listed event without one would have no place in it
        if ($listed && $dates === null) {
            throw new \RuntimeException(sprintf('Event config "%s" is listed but has no dates', $slug));
        }

        return new self(
            slug: $slug,
            name: $data['name'],
            colors: $data['colors'],
            theme: $data['theme'] ?? [],
            roles: $data['roles'] ?? [],
            features: $data['features'],
            listed: $listed,
            dates: $dates,
            raw: $data,
            dir: dirname($file),
        );
    }

    /** The per-event env var name: `ADMIN_TOKEN` → `ADMIN_TOKEN_OBROK27`. */
    public function envKey(string $name): string
    {
        return $name . '_' . strtoupper(str_replace('-', '_', $this->slug));
    }

    /**
     * A per-event setting from the environment. Deliberately no fallback to the
     * unsuffixed name: one instance serves every event, so an instance-wide
     * ADMIN_TOKEN or KISSJ_API_KEY would silently apply to all of them.
     */
    public function env(string $name, string $default = ''): string
    {
        $key = $this->envKey($name);
        // $_ENV can be empty under php-fpm's variables_order, where the process env still has it
        $value = $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function content(string $name): array
    {
        $file = $this->dir . '/content/' . $name . '.php';

        return is_file($file) ? require $file : [];
    }

    public function isEnabled(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }
}
