<?php

declare(strict_types=1);

namespace Tools\Fixtures;

/**
 * Draws one demo photo for bin/demo-media.php: abstract flat shapes on a flat background,
 * seeded by the file name, so a re-run writes the same picture. The full image is
 * 1200 × 800 (WebP q80) and its thumb a 256 × 256 centred cover crop of it (WebP q75),
 * small enough that both together stay within 60 KB. Needs GD with WebP.
 */
final class DemoPhoto
{
    public const int WIDTH = 1200;
    public const int HEIGHT = 800;
    public const int THUMB = 256;

    /** Writes `<dir>/<name>.webp` and `<dir>/<name>-thumb.webp`. */
    public static function write(string $name, string $dir): void
    {
        if (!function_exists('imagewebp')) {
            throw new \RuntimeException('GD with WebP support is required (imagewebp() is missing)');
        }
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Photo name is not [a-z0-9-]+: %s', $name));
        }

        mt_srand(crc32($name));
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT) ?: throw new \RuntimeException('Cannot create the image');
        imagealphablending($image, true);
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, self::colour($image, 0));

        $shapes = mt_rand(6, 9);
        for ($i = 0; $i < $shapes; $i++) {
            $colour = self::colour($image, mt_rand(25, 70));
            $x = mt_rand(0, self::WIDTH);
            $y = mt_rand(0, self::HEIGHT);
            if (mt_rand(0, 1) === 0) {
                $diameter = mt_rand(160, 640);
                imagefilledellipse($image, $x, $y, $diameter, $diameter, $colour);
            } else {
                imagefilledrectangle($image, $x - mt_rand(80, 360), $y - mt_rand(60, 260), $x + mt_rand(80, 360), $y + mt_rand(60, 260), $colour);
            }
        }

        $thumb = imagecreatetruecolor(self::THUMB, self::THUMB) ?: throw new \RuntimeException('Cannot create the thumb');
        $side = min(self::WIDTH, self::HEIGHT);
        imagecopyresampled($thumb, $image, 0, 0, intdiv(self::WIDTH - $side, 2), intdiv(self::HEIGHT - $side, 2), self::THUMB, self::THUMB, $side, $side);

        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException(sprintf('Cannot create %s', $dir));
        }
        if (!imagewebp($image, $dir . '/' . $name . '.webp', 80) || !imagewebp($thumb, $dir . '/' . $name . '-thumb.webp', 75)) {
            throw new \RuntimeException(sprintf('Cannot write the photo %s under %s', $name, $dir));
        }
    }

    /** A seeded colour; alpha 0 is opaque, 127 transparent. */
    private static function colour(\GdImage $image, int $alpha): int
    {
        $colour = imagecolorallocatealpha($image, mt_rand(20, 235), mt_rand(20, 235), mt_rand(20, 235), $alpha);
        if ($colour === false) {
            throw new \RuntimeException('Cannot allocate a colour');
        }

        return $colour;
    }
}
