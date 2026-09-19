<?php

declare(strict_types=1);

namespace App\Program;

/**
 * Maps kissj's `sections` list onto the shape the Program screen reads — see
 * docs/kissj-contract.md. It lives apart from KissjProgramProvider because the stub's
 * fixtures/sections.json is that same list, so both providers map it identically.
 */
final class Sections
{
    /**
     * @param mixed $raw the `sections` value as decoded, trusted for nothing
     * @return array<int, array{id: int, title: string, subTitle: ?string, image: ?string, attachment: ?array{href: string, label: string}}>
     *         keyed by id, in the order kissj sent them — which is the display order
     */
    public static function fromKissj(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ProgramDataException('kissj sections are not a list');
        }

        $sections = [];
        foreach ($raw as $section) {
            if (!is_array($section)) {
                throw new ProgramDataException('kissj section record is not an object');
            }
            if (!isset($section['id']) || !is_int($section['id'])) {
                throw new ProgramDataException('kissj section record has no integer id');
            }
            if (!isset($section['name']) || !is_string($section['name'])) {
                throw new ProgramDataException('kissj section record has no name');
            }

            $attachment = $section['attachment'] ?? null;
            if ($attachment !== null) {
                if (!is_array($attachment)
                    || !isset($attachment['url'], $attachment['label'])
                    || !is_string($attachment['url'])
                    || !is_string($attachment['label'])) {
                    throw new ProgramDataException('kissj section attachment needs a url and a label');
                }
                $attachment = ['href' => self::url($attachment['url']), 'label' => $attachment['label']];
            }

            $sections[$section['id']] = [
                'id' => $section['id'],
                'title' => $section['name'],
                'subTitle' => self::optionalString($section, 'subtitle'),
                'image' => ($image = self::optionalString($section, 'imageUrl')) === null ? null : self::url($image),
                'attachment' => $attachment,
            ];
        }

        return $sections;
    }

    private static function optionalString(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new ProgramDataException(sprintf('kissj section %s is not a string', $key));
        }

        return $value === '' ? null : $value;
    }

    /**
     * Both URLs end up in the page as a `src` and an `href`, and Twig's escaping does not
     * make `javascript:` inert. kissj sends absolute http(s) URLs; the stub's fixtures
     * carry paths relative to www/. Anything with another scheme is not a link.
     */
    private static function url(string $url): string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1 && preg_match('#^https?://#i', $url) !== 1) {
            throw new ProgramDataException(sprintf('kissj sent a URL that is not http(s): %s', $url));
        }

        return $url;
    }
}
