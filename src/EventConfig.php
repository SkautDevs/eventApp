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

    /**
     * The directives an event may extend. The rest (default-src, base-uri, form-action,
     * frame-ancestors, object-src) are the app's and fixed.
     */
    public const CSP_DIRECTIVES = ['script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'frame-src', 'media-src'];

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
        /**
         * Extra Content-Security-Policy origins, as ['frame-src' => ['https://…'], …].
         * Optional: no shipped event needs it — the map is derived and the fonts are self-hosted — it is
         * the escape hatch for an event that embeds a video or a photo CDN. A script-src
         * extra is allowed but defeats the nonce; avoid it.
         *
         * @var array<string, list<string>>
         */
        public readonly array $csp,
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

        $csp = $data['csp'] ?? [];
        if (!is_array($csp)) {
            throw new \RuntimeException(sprintf('Event config "%s" has an invalid csp (expected directive => origins)', $slug));
        }
        foreach ($csp as $directive => $origins) {
            if (!in_array($directive, self::CSP_DIRECTIVES, true)) {
                throw new \RuntimeException(sprintf('Event config "%s" has csp.%s, which is not one of %s', $slug, $directive, implode(', ', self::CSP_DIRECTIVES)));
            }
            if (!is_array($origins) || !array_is_list($origins)) {
                throw new \RuntimeException(sprintf('Event config "%s" has csp.%s that is not a list of origins', $slug, $directive));
            }
            foreach ($origins as $origin) {
                // an https origin and nothing else: no path, no keyword, no wildcard
                if (!is_string($origin) || preg_match('~^https://[a-z0-9-]+(\.[a-z0-9-]+)*(:[0-9]{1,5})?\z~i', $origin) !== 1) {
                    throw new \RuntimeException(sprintf('Event config "%s" has csp.%s with "%s", which is not an https:// origin without a path', $slug, $directive, is_scalar($origin) ? (string) $origin : get_debug_type($origin)));
                }
            }
        }

        return new self(
            slug: $slug,
            name: $data['name'],
            colors: $data['colors'],
            theme: $data['theme'] ?? [],
            roles: $data['roles'] ?? [],
            csp: $csp,
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

    /**
     * The font families the event renders with, for its offline set (App\Http\Fonts):
     * the first family of `theme.font` and of `theme.font-display`, quotes stripped, or
     * the stylesheet's own defaults (`themix`, `skautbold`) where unset, then the icons,
     * which every layout links. Each family once, in that order.
     *
     * @return list<string>
     */
    public function fontFamilies(): array
    {
        $families = [];
        foreach (['font' => 'themix', 'font-display' => 'skautbold'] as $key => $default) {
            $value = $this->theme[$key] ?? null;
            $first = is_string($value) ? trim(trim(explode(',', $value)[0]), '\'"') : '';
            $families[] = $first !== '' ? $first : $default;
        }
        $families[] = 'Font Awesome';

        return array_values(array_unique($families));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }
}
