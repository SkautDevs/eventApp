<?php

declare(strict_types=1);

namespace App;

final class EventConfig
{
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly array $colors,
        public readonly array $features,
        public readonly array $sections,
        public readonly array $raw,
        public readonly string $dir,
    ) {
    }

    public static function load(string $eventsDir, string $slug): self
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            throw new \RuntimeException(sprintf('Neplatný slug akce: "%s"', $slug));
        }

        $file = $eventsDir . '/' . $slug . '/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Neznámá akce: "%s" (%s nenalezen)', $slug, $file));
        }

        $data = require $file;
        foreach (['name', 'colors', 'features'] as $key) {
            if (!isset($data[$key])) {
                throw new \RuntimeException(sprintf('Konfigurace akce "%s" postrádá klíč "%s"', $slug, $key));
            }
        }

        return new self(
            slug: $slug,
            name: $data['name'],
            colors: $data['colors'],
            features: $data['features'],
            sections: $data['sections'] ?? [],
            raw: $data,
            dir: dirname($file),
        );
    }

    public function content(string $name): array
    {
        $file = $this->dir . '/content/' . $name . '.php';

        return is_file($file) ? require $file : [];
    }

    public function isEnabled(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }
}
