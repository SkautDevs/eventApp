<?php

declare(strict_types=1);

/**
 * Generates an event's favicon and app-icon set from one drawing.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-alpine sh -c \
 *     'apk add --no-cache libpng-dev >/dev/null && docker-php-ext-install gd >/dev/null \
 *      && php bin/event-icons.php <slug> <source.png> <#background>'
 *
 * Every file gets a content-hashed name (icon-<hash8>-<size>.png), because nginx and
 * .htaccess serve PNGs under www/events/ as immutable for a year: new bytes under an old
 * name would never reach a returning browser. The small favicons keep the drawing on
 * transparency; the home-screen icons are opaque on the background colour (iOS paints
 * transparency black), and the maskable one keeps the drawing inside Android's 80% safe
 * zone. It rewrites the manifest's icons and prints the config lines to paste.
 */

if ($argc !== 4 || !preg_match('/^[a-z0-9-]+$/', $argv[1]) || !preg_match('/^#[0-9a-f]{6}$/i', $argv[3])) {
    fwrite(STDERR, "usage: php bin/event-icons.php <slug> <source.png> <#rrggbb>\n");
    exit(2);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD is not loaded, see the usage line in this file\n");
    exit(2);
}

[$slug, $sourcePath, $hex] = [$argv[1], $argv[2], $argv[3]];
$root = dirname(__DIR__);
$dir = $root . '/www/events/' . $slug;
$source = imagecreatefrompng($sourcePath) ?: throw new RuntimeException('Cannot read ' . $sourcePath);

/** The drawing centred on a square canvas, scaled to $fill of its side. */
function icon(GdImage $source, int $size, ?array $rgb, float $fill): GdImage
{
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $ground = $rgb === null
        ? imagecolorallocatealpha($canvas, 0, 0, 0, 127)
        : imagecolorallocate($canvas, ...$rgb);
    imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1, $ground);
    imagealphablending($canvas, true);

    $w = imagesx($source);
    $h = imagesy($source);
    $scale = $size * $fill / max($w, $h);
    $dw = (int) round($w * $scale);
    $dh = (int) round($h * $scale);
    imagecopyresampled($canvas, $source, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);

    return $canvas;
}

$rgb = sscanf($hex, '#%02x%02x%02x');
$set = [
    '16' => icon($source, 16, null, 1.0),
    '32' => icon($source, 32, null, 1.0),
    '180' => icon($source, 180, $rgb, 0.8),
    '192' => icon($source, 192, $rgb, 0.8),
    '512' => icon($source, 512, $rgb, 0.8),
    'maskable-512' => icon($source, 512, $rgb, 0.6),
];

$paths = [];
foreach ($set as $name => $image) {
    ob_start();
    imagepng($image, null, 9);
    $bytes = (string) ob_get_clean();
    $file = 'icon-' . substr(hash('sha256', $bytes), 0, 8) . '-' . $name . '.png';
    file_put_contents($dir . '/' . $file, $bytes);
    $paths[$name] = 'events/' . $slug . '/' . $file;
}

$manifestPath = $dir . '/site.webmanifest';
$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$manifest['icons'] = [
    ['src' => '/' . $paths['192'], 'sizes' => '192x192', 'type' => 'image/png'],
    ['src' => '/' . $paths['512'], 'sizes' => '512x512', 'type' => 'image/png'],
    ['src' => '/' . $paths['maskable-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
];
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo "Paste into events/{$slug}/config.php, 'assets':\n";
echo "        'favicon16' => '{$paths['16']}',\n";
echo "        'favicon32' => '{$paths['32']}',\n";
echo "        'appleTouch' => '{$paths['180']}',\n";
echo "Old icon files are left in place; delete the ones nothing links any more.\n";
