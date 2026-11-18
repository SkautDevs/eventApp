<?php

declare(strict_types=1);

namespace App\Module\Admin\View;

/**
 * What the send form shows in its fields: the target (a programme id as a string, `''`
 * for everyone), the title, the text and the Podpis. Empty but for the remembered Podpis
 * on a fresh form; what was typed on a refused one.
 */
final readonly class FormValues
{
    public function __construct(
        public string $target,
        public string $title,
        public string $body,
        public string $signature,
    ) {}
}
