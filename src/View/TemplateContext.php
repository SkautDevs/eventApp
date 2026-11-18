<?php

declare(strict_types=1);

namespace App\View;

/**
 * What a page object hands its template: its public properties by name, one template
 * variable each. Pure.
 */
final class TemplateContext
{
    /** @return array<string, mixed> */
    public static function of(object $page): array
    {
        $context = [];
        foreach (get_object_vars($page) as $name => $value) {
            // a declared property's name is a string; only a dynamic one could be an integer
            if (is_string($name)) {
                $context[$name] = $value;
            }
        }

        return $context;
    }
}
