<?php

declare(strict_types=1);

namespace App\Program;

/**
 * One programme section as the screens read it: kissj's section (Sections::fromKissj()
 * maps its `name` and `subtitle`), with no subtitle as null. Its row form
 * (fromArray(), toArray()) `{id, title, subTitle}` is what the cache's `list` entry keeps.
 */
final readonly class Section
{
    public function __construct(public int $id, public string $title, public ?string $subTitle) {}

    /**
     * A row of the cache's `list` entry. Lenient like Programme::fromArray(): a missing or
     * irregular field is 0, '' or null.
     *
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $id = $row['id'] ?? null;
        $title = $row['title'] ?? null;
        $subTitle = $row['subTitle'] ?? null;

        return new self(is_int($id) ? $id : 0, is_string($title) ? $title : '', is_string($subTitle) ? $subTitle : null);
    }

    /** @return array{id: int, title: string, subTitle: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'subTitle' => $this->subTitle];
    }
}
