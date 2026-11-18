<?php

declare(strict_types=1);

namespace App\Event;

/**
 * An event's `handbook`: the PDF (`file`, a path under www/, which may not be uploaded
 * yet) and the name it downloads as (`downloadName`).
 */
final readonly class EventHandbook
{
    public function __construct(
        public string $file,
        public string $downloadName,
    ) {}

    /**
     * The config's `handbook` map, or null without a string `file`: there is nothing to
     * download then. A missing `downloadName` is `''`.
     *
     * @param array<mixed> $handbook
     */
    public static function fromArray(array $handbook): ?self
    {
        $file = $handbook['file'] ?? null;
        $downloadName = $handbook['downloadName'] ?? null;

        return is_string($file) ? new self($file, is_string($downloadName) ? $downloadName : '') : null;
    }
}
