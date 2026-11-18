<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ScriptGraph;
use PHPUnit\Framework\TestCase;

/** The static import graph of the scripts under <docroot>/js, read from their source. */
final class ScriptGraphTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/script-graph-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/js', 0o777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, string> $files path under js/ => source */
    private function graph(array $files): ScriptGraph
    {
        foreach ($files as $path => $source) {
            $file = $this->dir . '/js/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o777, true);
            }
            file_put_contents($file, $source);
        }

        return new ScriptGraph($this->dir);
    }

    public function testADefaultImport(): void
    {
        $graph = $this->graph(['a/index.js' => "import thing from './b.js';\nthing();\n", 'a/b.js' => "export default () => {};\n"]);

        self::assertSame(['a/b.js'], $graph->imports('a/index.js'));
        self::assertSame([], $graph->unresolved());
    }

    public function testANamedImportOverThreeLines(): void
    {
        $graph = $this->graph([
            'a/index.js' => "import {\n\tone,\n\ttwo,\n} from './b.js';\n",
            'a/b.js' => "export const one = 1;\nexport const two = 2;\n",
        ]);

        self::assertSame(['a/b.js'], $graph->imports('a/index.js'));
    }

    public function testABareImport(): void
    {
        $graph = $this->graph(['a/index.js' => "import './b.js';\n", 'a/b.js' => "document.title = 'x';\n"]);

        self::assertSame(['a/b.js'], $graph->imports('a/index.js'));
    }

    public function testAParentPathAndTheLoadOrder(): void
    {
        $graph = $this->graph([
            'a/index.js' => "import '../ui/one.js';\nimport '../two.js';\n",
            'ui/one.js' => "import '../three.js';\n",
            'two.js' => '',
            'three.js' => '',
        ]);

        // a module's own imports load before it, and in the order they are written
        self::assertSame(['three.js', 'ui/one.js', 'two.js'], $graph->imports('a/index.js'));
    }

    public function testAReExportCountsAsAnImport(): void
    {
        $graph = $this->graph(['a/index.js' => "export {one} from './b.js';\nexport * from './c.js';\n", 'a/b.js' => '', 'a/c.js' => '']);

        self::assertSame(['a/b.js', 'a/c.js'], $graph->imports('a/index.js'));
    }

    public function testACycleTerminatesAndNamesEachFileOnce(): void
    {
        $graph = $this->graph([
            'a/index.js' => "import './b.js';\n",
            'a/b.js' => "import './c.js';\n",
            'a/c.js' => "import './b.js';\nimport './index.js';\n",
        ]);

        self::assertSame(['a/c.js', 'a/b.js'], $graph->imports('a/index.js'));
        self::assertSame([], $graph->unresolved());
    }

    public function testAnImportInsideACommentIsIgnored(): void
    {
        $graph = $this->graph([
            'a/index.js' => "// import './gone.js';\n/*\nimport './gone.js';\n*/\nimport './b.js';\n",
            'a/b.js' => '',
        ]);

        self::assertSame(['a/b.js'], $graph->imports('a/index.js'));
        self::assertSame([], $graph->unresolved());
    }

    public function testACommentMarkerInsideAStringStartsNoComment(): void
    {
        $graph = $this->graph([
            'a/index.js' => "const g = '/js/*';\nconst u = \"http://x\";\nconst t = `a /* b`;\nimport './b.js';\n/* doc */\nimport './c.js';\n",
            'a/b.js' => '',
            'a/c.js' => '',
        ]);

        self::assertSame(['a/b.js', 'a/c.js'], $graph->imports('a/index.js'));
    }

    public function testAnInlineCommentInsideAMultiLineImportIsStripped(): void
    {
        $graph = $this->graph([
            'a/index.js' => "import {\n\tone, // don't\n\ttwo, /* it's */\n} from './b.js';\n",
            'a/b.js' => "export const one = 1;\nexport const two = 2;\n",
        ]);

        self::assertSame(['a/b.js'], $graph->imports('a/index.js'));
    }

    public function testStripCommentsKeepsStringsAndLineBreaks(): void
    {
        $source = "const a = 'it\\'s // not'; // gone\nconst b = \"/* kept */\"; /* gone\nacross */ const c = `x\\` // y`;\n";

        self::assertSame(
            "const a = 'it\\'s // not'; \nconst b = \"/* kept */\"; \n const c = `x\\` // y`;\n",
            ScriptGraph::stripComments($source),
        );
    }

    public function testAMissingFileAndANonRelativeSpecifierAreUnresolved(): void
    {
        $graph = $this->graph(['a/index.js' => "import './missing.js';\nimport '/js/a/b.js';\nimport 'lib';\n", 'a/b.js' => '']);

        self::assertSame([
            'a/index.js -> ./missing.js',
            'a/index.js -> /js/a/b.js',
            'a/index.js -> lib',
        ], $graph->unresolved());
        self::assertSame([], $graph->imports('a/index.js'));
    }

    public function testOrphansAreTheFilesNoEntryReaches(): void
    {
        $graph = $this->graph([
            'a/index.js' => "import './b.js';\n",
            'a/b.js' => '',
            'c/index.js' => '',
            'lost.js' => '',
        ]);

        self::assertSame(['c/index.js', 'lost.js'], $graph->orphans(['a/index.js']));
        self::assertSame(['lost.js'], $graph->orphans(['a/index.js', 'c/index.js']));
    }

    public function testTheGraphIsKeptAcrossInstancesAndAChangedFileIsReadAtOnce(): void
    {
        $this->graph(['a/index.js' => "import './b.js';\n", 'a/b.js' => '']);
        $cache = $this->dir . '/cache';
        self::assertSame(['a/b.js'], (new ScriptGraph($this->dir, $cache))->imports('a/index.js'));
        $entries = glob($cache . '/scripts-graph-*.json') ?: [];
        self::assertCount(1, $entries);

        // a later instance reads the entry instead of parsing: a planted graph comes back
        file_put_contents($entries[0], '{"a/index.js":{"imports":["planted.js"],"unresolved":[]}}');
        self::assertSame(['planted.js'], (new ScriptGraph($this->dir, $cache))->imports('a/index.js'));

        // a changed file changes the signature, so the tree is parsed again and the old entry goes
        file_put_contents($this->dir . '/js/a/index.js', "import './b.js';\nimport './c.js';\n");
        file_put_contents($this->dir . '/js/a/c.js', '');
        self::assertSame(['a/b.js', 'a/c.js'], (new ScriptGraph($this->dir, $cache))->imports('a/index.js'));
        self::assertCount(1, glob($cache . '/scripts-graph-*.json') ?: []);
    }

    public function testAMalformedCachedGraphIsIgnored(): void
    {
        $this->graph(['a/index.js' => "import './b.js';\n", 'a/b.js' => '']);
        $cache = $this->dir . '/cache';
        (new ScriptGraph($this->dir, $cache))->imports('a/index.js');
        $entries = glob($cache . '/scripts-graph-*.json') ?: [];
        self::assertCount(1, $entries);

        file_put_contents($entries[0], '{"a/index.js":{"imports":[1],"unresolved":[]}}');
        self::assertSame(['a/b.js'], (new ScriptGraph($this->dir, $cache))->imports('a/index.js'));
    }
}
