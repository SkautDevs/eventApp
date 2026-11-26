<?php

declare(strict_types=1);

namespace App\Program;

/**
 * One photo of a programme (kissj contract 3.1): the full image, its square thumbnail and
 * the alt text, '' for none. Its row form is kissj's own `{url, thumbUrl, alt}`.
 */
final readonly class ProgrammeImage
{
    public function __construct(
        public string $url,
        public string $thumbUrl,
        public string $alt,
    ) {}

    /**
     * Lenient: null unless `url` and `thumbUrl` are non-empty strings, so a malformed
     * element is skipped rather than failing the list; an `alt` that is no string is ''.
     */
    public static function fromArray(mixed $row): ?self
    {
        if (!is_array($row)) {
            return null;
        }
        $url = $row['url'] ?? null;
        $thumbUrl = $row['thumbUrl'] ?? null;
        if (!is_string($url) || $url === '' || !is_string($thumbUrl) || $thumbUrl === '') {
            return null;
        }
        $alt = $row['alt'] ?? null;

        return new self($url, $thumbUrl, is_string($alt) ? $alt : '');
    }

    /** @return array{url: string, thumbUrl: string, alt: string} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'thumbUrl' => $this->thumbUrl, 'alt' => $this->alt];
    }
}
