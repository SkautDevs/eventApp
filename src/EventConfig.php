<?php

declare(strict_types=1);

namespace App;

use App\Event\Emergency;
use App\Event\EventAssets;
use App\Event\EventDates;
use App\Event\EventHandbook;
use App\Event\EventHomepage;
use App\Event\EventMap;
use App\Event\EventRoles;
use App\Event\Link;

/**
 * One event, loaded and validated from `events/<slug>/config.php` (EventConfig::load()):
 * typed properties for every key the app reads, with the maps (`colors`, `theme`, the role
 * sets) in the config's own key order, since the layout emits them into the inline
 * `<style>` in that order. It is the `event` Twig global as it is. Its per-event content
 * files under `content/` are read by links() and emergency().
 */
final readonly class EventConfig
{
    /**
     * The shared www/style.css deliberately carries no colour fallbacks, so a missing
     * key does not degrade — it renders as `unset`. Fail at boot instead of shipping
     * an event whose headings inherit the body colour.
     */
    private const array REQUIRED_COLORS = ['background', 'link', 'base', 'darker', 'primary', 'text', 'text-invert'];

    /**
     * The directives an event may extend. The rest (default-src, base-uri, form-action,
     * frame-ancestors, object-src) are the app's and fixed.
     */
    public const array CSP_DIRECTIVES = ['script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'frame-src', 'media-src'];

    /**
     * @param list<string> $features
     * @param array<string, string> $colors
     * @param array<string, string> $theme
     * @param array<string, list<string>> $csp
     */
    private function __construct(
        public string $slug,
        /** The event's directory under events/, for its content files. */
        public string $dir,
        public string $name,
        /** The home tab's label and the name the screens' sentences use; null falls back to the name. */
        public ?string $shortName,
        /** Shown in the picker at `/`. An unlisted event still works at its URL. */
        public bool $listed,
        public ?EventDates $dates,
        public array $features,
        public array $colors,
        /**
         * Optional look-and-feel overrides, emitted as --theme-* custom properties.
         * Unlike $colors no key is required and it may be empty: every rule
         * in www/style.css that reads one carries today's value as its var() fallback,
         * so an event that declares no theme renders exactly as it did before the map
         * existed. Values are raw CSS and may reference the palette, e.g.
         * 'ruler-bg' => 'var(--color-primary)'.
         */
        public array $theme,
        /**
         * Semantic colour roles. Optional like $theme: www/style.css falls every role back
         * to the palette expression its rules used before roles existed, so an event that
         * declares none renders exactly as it did. An event without a dark set is
         * light-only and its layout shows no mode toggle.
         */
        public EventRoles $roles,
        /**
         * Extra Content-Security-Policy origins, as ['frame-src' => ['https://…'], …].
         * Optional: no shipped event needs it — the map is derived and the fonts are self-hosted — it is
         * the escape hatch for an event that embeds a video or a photo CDN. A script-src
         * extra is allowed but defeats the nonce; avoid it.
         */
        public array $csp,
        public EventAssets $assets,
        public EventHomepage $homepage,
        public ?EventMap $map,
        public ?EventHandbook $handbook,
    ) {}

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
        if (!is_array($data)) {
            $data = [];
        }
        foreach (['name', 'colors', 'features'] as $key) {
            if (!isset($data[$key])) {
                throw new \RuntimeException(sprintf('Event config "%s" is missing the key "%s"', $slug, $key));
            }
        }
        if (!is_string($data['name'])) {
            throw new \RuntimeException(sprintf('Event config "%s" has a name that is not a string', $slug));
        }
        $features = $data['features'];
        if (!is_array($features) || !array_is_list($features) || array_filter($features, is_string(...)) !== $features) {
            throw new \RuntimeException(sprintf('Event config "%s" has features that are not a list of strings', $slug));
        }

        if (in_array('info', $features, true) && !in_array('push', $features, true)) {
            throw new \RuntimeException(sprintf('Event config "%s" enables info without push: Informace lists the sent notifications', $slug));
        }

        $colors = self::stringMap($data['colors'], $slug, 'colors');
        foreach (self::REQUIRED_COLORS as $color) {
            if (!isset($colors[$color])) {
                throw new \RuntimeException(sprintf('Event config "%s" is missing the colour "%s"', $slug, $color));
            }
        }
        $theme = self::stringMap($data['theme'] ?? [], $slug, 'theme');
        $roles = $data['roles'] ?? [];
        if (!is_array($roles)) {
            throw new \RuntimeException(sprintf('Event config "%s" has invalid roles (expected light and dark)', $slug));
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
            $dates = new EventDates($dates['start'], $dates['end']);
        }
        // the picker orders by date, so a listed event without one would have no place in it
        if ($listed && $dates === null) {
            throw new \RuntimeException(sprintf('Event config "%s" is listed but has no dates', $slug));
        }

        $csp = $data['csp'] ?? [];
        if (!is_array($csp)) {
            throw new \RuntimeException(sprintf('Event config "%s" has an invalid csp (expected directive => origins)', $slug));
        }
        $origins = [];
        foreach ($csp as $directive => $list) {
            if (!in_array($directive, self::CSP_DIRECTIVES, true)) {
                throw new \RuntimeException(sprintf('Event config "%s" has csp.%s, which is not one of %s', $slug, $directive, implode(', ', self::CSP_DIRECTIVES)));
            }
            if (!is_array($list) || !array_is_list($list)) {
                throw new \RuntimeException(sprintf('Event config "%s" has csp.%s that is not a list of origins', $slug, $directive));
            }
            $origins[$directive] = [];
            foreach ($list as $origin) {
                // an https origin and nothing else: no path, no keyword, no wildcard
                if (!is_string($origin) || preg_match('~^https://[a-z0-9-]+(\.[a-z0-9-]+)*(:[0-9]{1,5})?\z~i', $origin) !== 1) {
                    throw new \RuntimeException(sprintf('Event config "%s" has csp.%s with "%s", which is not an https:// origin without a path', $slug, $directive, is_scalar($origin) ? (string) $origin : get_debug_type($origin)));
                }
                $origins[$directive][] = $origin;
            }
        }

        $shortName = $data['shortName'] ?? null;
        $assets = $data['assets'] ?? null;
        $homepage = $data['homepage'] ?? null;
        $map = $data['map'] ?? null;
        $handbook = $data['handbook'] ?? null;

        return new self(
            slug: $slug,
            dir: dirname($file),
            name: $data['name'],
            shortName: is_string($shortName) ? $shortName : null,
            listed: $listed,
            dates: $dates,
            features: $features,
            colors: $colors,
            theme: $theme,
            roles: new EventRoles(
                self::stringMap($roles['light'] ?? [], $slug, 'roles.light'),
                self::stringMap($roles['dark'] ?? [], $slug, 'roles.dark'),
            ),
            csp: $origins,
            assets: EventAssets::fromArray(is_array($assets) ? $assets : []),
            homepage: EventHomepage::fromArray(is_array($homepage) ? $homepage : []),
            map: is_array($map) ? EventMap::fromArray($map) : null,
            handbook: is_array($handbook) ? EventHandbook::fromArray($handbook) : null,
        );
    }

    /**
     * Informace's quick links and Domů's highlighted ones, from `content/links.php`, in the
     * file's order; `[]` for an event without the file.
     *
     * @return list<Link>
     */
    public function links(): array
    {
        $links = [];
        foreach ($this->content('links') ?? [] as $row) {
            if (is_array($row)) {
                $links[] = Link::fromArray($row);
            }
        }

        return $links;
    }

    /** Informace's crisis contact, from `content/emergency.php`; null for an event without the file. */
    public function emergency(): ?Emergency
    {
        $content = $this->content('emergency');

        return $content === null ? null : Emergency::fromArray($content);
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

    /**
     * A map with string keys and string values, in its own order, or a refusal to boot. A
     * number is taken as its string form (`700` as `'700'`), which is how the layout has
     * always printed it.
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $value, string $slug, string $key): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException(sprintf('Event config "%s" has %s that is not a map', $slug, $key));
        }
        $map = [];
        foreach ($value as $name => $item) {
            if (!is_string($name) || !(is_string($item) || is_int($item) || is_float($item))) {
                throw new \RuntimeException(sprintf('Event config "%s" has %s.%s that is not a string', $slug, $key, $name));
            }
            $map[$name] = (string) $item;
        }

        return $map;
    }

    /**
     * The array an event's `content/<name>.php` returns, or null without the file (or when
     * it returns no array).
     *
     * @return array<mixed>|null
     */
    private function content(string $name): ?array
    {
        $file = $this->dir . '/content/' . $name . '.php';
        if (!is_file($file)) {
            return null;
        }
        $content = require $file;

        return is_array($content) ? $content : null;
    }
}
