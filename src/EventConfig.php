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
        public readonly array $raw,
        public readonly string $dir,
    ) {
    }

    public static function load(string $eventsDir, string $slug): self
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
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

        foreach (self::REQUIRED_COLORS as $color) {
            if (!isset($data['colors'][$color])) {
                throw new \RuntimeException(sprintf('Event config "%s" is missing the colour "%s"', $slug, $color));
            }
        }

        return new self(
            slug: $slug,
            name: $data['name'],
            colors: $data['colors'],
            theme: $data['theme'] ?? [],
            roles: $data['roles'] ?? [],
            features: $data['features'],
            raw: $data,
            dir: dirname($file),
        );
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
