<?php

declare(strict_types=1);

namespace App\Module\Programs\View;

/**
 * What a sheet's footer offers (SheetBuilder::footer()): `state` is `unpaired`, `before`,
 * `after`, `none`, `fixed`, `register` or `unregister` — a string, because the template
 * compares it with one — and `line` the sentence of a window that is not open.
 */
final readonly class SheetFooter
{
    public function __construct(
        public string $state,
        public ?string $line,
    ) {}
}
