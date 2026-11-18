<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ScriptVersion;
use PHPUnit\Framework\TestCase;

final class ScriptVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/script-version-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/js', 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function put(string $path, string $content): void
    {
        $file = $this->root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, $content);
    }

    public function testTheHashChangesWhenAnyFileChangesIsAddedOrIsRenamed(): void
    {
        $this->put('js/a.js', 'one');
        $this->put('js/dir/b.js', 'two');
        $before = (new ScriptVersion($this->root))->hash();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $before);

        // a different length: the memo is keyed by path, mtime and size, and a same-size rewrite within one second is the collision AssetVersionTest already documents
        $this->put('js/dir/b.js', 'three');
        self::assertNotSame($before, $changed = (new ScriptVersion($this->root))->hash());
        $this->put('js/c.js', '');
        self::assertNotSame($changed, $added = (new ScriptVersion($this->root))->hash());
        rename($this->root . '/js/c.js', $this->root . '/js/d.js');
        self::assertNotSame($added, (new ScriptVersion($this->root))->hash());
    }

    public function testTheSameTreeGivesTheSameHashAndTheUrlCarriesIt(): void
    {
        $this->put('js/a.js', 'one');
        $version = new ScriptVersion($this->root);
        self::assertSame($version->hash(), (new ScriptVersion($this->root))->hash());
        self::assertSame('/js/' . $version->hash() . '/a.js', $version->url('a.js'));
        self::assertSame(['a.js'], $version->files());
    }

    public function testAMissingFileFailsLoudly(): void
    {
        $this->expectExceptionMessage('Script "gone.js" does not exist');
        (new ScriptVersion($this->root))->url('gone.js');
    }
}
