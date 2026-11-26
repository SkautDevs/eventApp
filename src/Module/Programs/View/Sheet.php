<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

use App\Program\ProgrammeImage;

/**
 * One programme's sheet (SheetBuilder::buildDetail()): its name, Kdy, the place as given,
 * the perex, the lector and what to bring, the section's title, the timeline page its deep
 * link opens, the section it belongs to (the deep link's `section-<sectionId>-program-<id>`,
 * 0 when the record names none), and whether it is the participant's or preregistered. ScheduleBuilder leaves
 * `messages` empty and `footer` null; ShowAction then makes the completed() copy with the
 * organisers' messages and what the footer offers. `images` are the programme's photos,
 * the sheet's gallery, and `imageKeys` the morph's key of each one's frame and dot; `placeId`
 * is its place, and `placeHref` the link to that place on Místa, null until completed() is
 * given one.
 */
final readonly class Sheet
{
    /**
     * One key per photo, in the order of `images`: imageKey() of its URL, and a second
     * appearance of the same URL gets `-2`, a third `-3`, so the keys stay unique. A frame
     * keyed by its photo, not its position, makes a revalidation that reorders or replaces
     * the photos insert a fresh frame (faded in, the placeholder on a failure) rather than
     * write a new `src` into a frame gallery.js has already marked loaded.
     *
     * @var list<string>
     */
    public array $imageKeys;

    /**
     * @param list<SheetMessage> $messages
     * @param list<ProgrammeImage> $images in the source's order
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $when,
        public ?string $location,
        public ?string $perex,
        public ?string $lector,
        public ?string $tools,
        public ?string $section,
        public ?string $page,
        public int $sectionId,
        public bool $registered,
        public bool $preregistered,
        public array $messages = [],
        public ?SheetFooter $footer = null,
        public array $images = [],
        public ?int $placeId = null,
        public ?string $placeHref = null,
    ) {
        $keys = [];
        $seen = [];
        foreach ($images as $image) {
            $key = self::imageKey($image->url);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $keys[] = $seen[$key] === 1 ? $key : $key . '-' . $seen[$key];
        }
        $this->imageKeys = $keys;
    }

    /** A photo's key: a short hash of its URL, the same on every render. */
    public static function imageKey(string $url): string
    {
        return substr(hash('sha256', $url), 0, 12);
    }

    /**
     * A copy with the organisers' messages, the footer and the link to the place.
     *
     * @param list<SheetMessage> $messages
     */
    public function completed(array $messages, ?SheetFooter $footer, ?string $placeHref = null): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->when,
            $this->location,
            $this->perex,
            $this->lector,
            $this->tools,
            $this->section,
            $this->page,
            $this->sectionId,
            $this->registered,
            $this->preregistered,
            $messages,
            $footer,
            $this->images,
            $this->placeId,
            $placeHref,
        );
    }
}
