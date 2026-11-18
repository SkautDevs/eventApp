<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's `assets`: paths under www/ for its drawings, icons and own stylesheet. Each
 * is kept as the config gives it, so a declared `''` stays `''` and an undeclared one is
 * null; the templates treat both as "none". The tab bar's mark is `menuLogo` with its
 * dark-mode twin `menuLogoDark`, `menuLogoOnState` is the drawing for a surface filled
 * with `state`, `appBarLogo` overrides it on the app bar alone, `mainLogo`/`mainLogoDark`
 * are Domů's, `stylesheet` is the event's non-colour look, and `favicon16`, `favicon32`,
 * `appleTouch` and `pinnedTab` are the head icons.
 */
final readonly class EventAssets
{
    /** @param array<string, string> $declared every non-empty path the config names, stylesheet aside, in its order */
    public function __construct(
        public ?string $menuLogo,
        public ?string $menuLogoDark,
        public ?string $menuLogoOnState,
        public ?string $appBarLogo,
        public ?string $mainLogo,
        public ?string $mainLogoDark,
        public ?string $notificationIcon,
        public ?string $stylesheet,
        public ?string $favicon16,
        public ?string $favicon32,
        public ?string $appleTouch,
        public ?string $pinnedTab,
        private array $declared,
    ) {}

    /**
     * The config's `assets` map. A value that is not a string counts as undeclared.
     *
     * @param array<mixed> $assets
     */
    public static function fromArray(array $assets): self
    {
        $string = static fn(string $key): ?string => is_string($assets[$key] ?? null) ? $assets[$key] : null;
        $declared = [];
        foreach ($assets as $key => $path) {
            if (is_string($key) && $key !== 'stylesheet' && is_string($path) && $path !== '') {
                $declared[$key] = $path;
            }
        }

        return new self(
            menuLogo: $string('menuLogo'),
            menuLogoDark: $string('menuLogoDark'),
            menuLogoOnState: $string('menuLogoOnState'),
            appBarLogo: $string('appBarLogo'),
            mainLogo: $string('mainLogo'),
            mainLogoDark: $string('mainLogoDark'),
            notificationIcon: $string('notificationIcon'),
            stylesheet: $string('stylesheet'),
            favicon16: $string('favicon16'),
            favicon32: $string('favicon32'),
            appleTouch: $string('appleTouch'),
            pinnedTab: $string('pinnedTab'),
            declared: $declared,
        );
    }

    /**
     * Every declared non-empty path except the stylesheet (which the offline set lists by
     * its versioned URL), key => path in the config's own order, a key this class has no
     * property for included: the files the offline copy carries.
     *
     * @return array<string, string>
     */
    public function paths(): array
    {
        return $this->declared;
    }
}
