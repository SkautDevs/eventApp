<?php

declare(strict_types=1);

namespace App\Toast;

/**
 * One server toast as a page renders it (templates/_toast.twig): a title and an optional
 * second line. What waits in the session is Toasts' business; this is what a page shows.
 */
final readonly class Toast
{
    public function __construct(public string $title, public ?string $line = null) {}
}
