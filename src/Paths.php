<?php

declare(strict_types=1);

namespace App;

/**
 * The app's two directories, in the container: the project root and the document root
 * under it. An action that needs a file path takes this rather than computing one from
 * `__DIR__`, which changes meaning when the class moves a directory deeper.
 */
final readonly class Paths
{
    public function __construct(
        public string $root,
        public string $www,
    ) {}
}
