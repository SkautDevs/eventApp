<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * One programme's sheet (SheetBuilder::buildDetail()): its name, Kdy, the place as given,
 * the perex, the lector and what to bring, the section's title, the timeline page its deep
 * link opens, the section it belongs to (the deep link's `section-<sectionId>-program-<id>`,
 * 0 when the record names none), and whether it is the participant's or preregistered. ScheduleBuilder leaves
 * `messages` empty and `footer` null; ShowAction then makes the completed() copy with the
 * organisers' messages and what the footer offers.
 */
final readonly class Sheet
{
    /** @param list<SheetMessage> $messages */
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
    ) {}

    /**
     * A copy with the organisers' messages and the footer.
     *
     * @param list<SheetMessage> $messages
     */
    public function completed(array $messages, ?SheetFooter $footer): self
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
        );
    }
}
