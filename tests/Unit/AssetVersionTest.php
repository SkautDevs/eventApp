<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\AssetVersion;
use PHPUnit\Framework\TestCase;

final class AssetVersionTest extends TestCase
{
    private string $dir;

    private AssetVersion $assets;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/asset-version-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->assets = new AssetVersion($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testTheHashIsEightHexCharactersOfTheSha256(): void
    {
        file_put_contents($this->dir . '/style.css', 'body {}');

        self::assertSame(substr(hash('sha256', 'body {}'), 0, 8), $this->assets->hash('style.css'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $this->assets->hash('style.css'));
    }

    public function testTheSameBytesGiveTheSameHash(): void
    {
        file_put_contents($this->dir . '/a.js', 'same');
        file_put_contents($this->dir . '/b.js', 'same');

        self::assertSame($this->assets->hash('a.js'), $this->assets->hash('b.js'));
    }

    public function testAWriteChangesTheHash(): void
    {
        file_put_contents($this->dir . '/app.js', 'one');
        $before = $this->assets->hash('app.js');

        file_put_contents($this->dir . '/app.js', 'three');

        self::assertNotSame($before, $this->assets->hash('app.js'));
        self::assertSame(substr(hash('sha256', 'three'), 0, 8), $this->assets->hash('app.js'));
    }

    public function testTheMemoIsKeyedByModificationTimeAndSize(): void
    {
        $file = $this->dir . '/app.js';
        file_put_contents($file, 'aaaa');
        touch($file, 1_700_000_000);
        $first = $this->assets->hash('app.js');

        // same size, same mtime: the memo answers and the file is not read again
        file_put_contents($file, 'bbbb');
        touch($file, 1_700_000_000);
        self::assertSame($first, $this->assets->hash('app.js'));

        // a new mtime is a new key, so the bytes are read again
        touch($file, 1_700_000_100);
        self::assertSame(substr(hash('sha256', 'bbbb'), 0, 8), $this->assets->hash('app.js'));
    }

    public function testTheUrlIsThePathWithTheHash(): void
    {
        file_put_contents($this->dir . '/push.js', 'x');

        self::assertSame('push.js?v=' . substr(hash('sha256', 'x'), 0, 8), $this->assets->url('push.js'));
    }

    public function testAMissingFileFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Asset "gone.js" does not exist');

        $this->assets->hash('gone.js');
    }
}
