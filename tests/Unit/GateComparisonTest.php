<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tools\Gate\Comparison;

final class GateComparisonTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($dir);
        }
    }

    /** @param array<string, string> $files relative path => content */
    private function tree(array $files): string
    {
        $dir = sys_get_temp_dir() . '/gate-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $this->dirs[] = $dir;
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($dir . '/' . $path))) {
                mkdir(dirname($dir . '/' . $path), 0700, true);
            }
            file_put_contents($dir . '/' . $path, $content);
        }

        return $dir;
    }

    public function testFrontendModeDropsScriptAndStylesheetElementsOnly(): void
    {
        $html = '<link rel="stylesheet" href="style.css?v=H"><script src="app.js?v=H" defer></script><script nonce="N">x</script><p>text</p>';
        self::assertSame('<script nonce="N">x</script><p>text</p>', Comparison::frontend($html));
    }

    public function testFrontendModeDropsModulePreloadsAndWholeLinesWithoutJoiningOthers(): void
    {
        $html = "<head>\n\t<link rel=\"modulepreload\" href=\"/js/H/app.js\">\n\t<script type=\"module\" src=\"/js/H/app.js\"></script>\n\t<link rel=\"icon\" href=\"/x.png\">\n</head>";
        self::assertSame("<head>\n\t<link rel=\"icon\" href=\"/x.png\">\n</head>", Comparison::frontend($html));
    }

    public function testFrontendModeDropsScriptAndStylesheetAssetsFromThePrecacheList(): void
    {
        $json = '{"version":"0a1b2c3d","documents":["/a/"],"assets":["/style.css?v=H","/js/H/app.js","/fonts/x.woff2"],"optional":[]}';
        self::assertSame('{"documents":["/a/"],"assets":["/fonts/x.woff2"],"optional":[]}', Comparison::frontend($json));
    }

    public function testFrontendModeFindsThePrecacheListInAResponse(): void
    {
        $response = "HTTP/1.1 200 OK\nContent-Type: application/json\n\n"
            . '{"version":"0a1b2c3d","documents":["/a/"],"assets":["/app.js?v=H","/b.png"],"optional":[]}';
        self::assertSame(
            "HTTP/1.1 200 OK\nContent-Type: application/json\n\n" . '{"documents":["/a/"],"assets":["/b.png"],"optional":[]}',
            Comparison::frontend($response),
        );
    }

    public function testIdenticalTreesHaveNoDifference(): void
    {
        $left = $this->tree(['x/01-a.txt' => "<script nonce=\"aa\">\n", 'x/02-b.txt' => 'b']);
        $right = $this->tree(['x/01-a.txt' => "<script nonce=\"bb\">\n", 'x/02-b.txt' => 'b']);

        $result = Comparison::run($left, $right, frontend: false);
        self::assertSame(0, $result->differences);
        self::assertSame(2, $result->files);
    }

    public function testAFileOnOneSideOnlyIsADifference(): void
    {
        $left = $this->tree(['x/01-a.txt' => 'a', 'x/02-b.txt' => 'b']);
        $right = $this->tree(['x/01-a.txt' => 'a']);

        self::assertSame(1, Comparison::run($left, $right, frontend: false)->differences);
        self::assertSame(1, Comparison::run($right, $left, frontend: false)->differences);
    }

    public function testADifferenceIsReportedWithItsPathAndAShortDiff(): void
    {
        $left = $this->tree(['x/01-a.txt' => implode("\n", range(1, 100)) . "\n"]);
        $right = $this->tree(['x/01-a.txt' => implode("\n", array_map(static fn(int $n): string => $n . '!', range(1, 100))) . "\n"]);

        $result = Comparison::run($left, $right, frontend: false);
        self::assertSame(1, $result->differences);
        self::assertStringContainsString('x/01-a.txt', $result->report);
        // the path line, at most 40 lines of diff, and the elision note
        self::assertLessThanOrEqual(42, substr_count($result->report, "\n"));
        self::assertMatchesRegularExpression('/… \d+ more diff lines\n\z/', $result->report);
    }

    public function testAGzipFileIsComparedByItsContent(): void
    {
        $left = $this->tree(['r/home.dom.html.gz' => gzencode("<p>a</p>\n", 9)]);
        $same = $this->tree(['r/home.dom.html.gz' => gzencode("<p>a</p>\n", 1)]);
        $other = $this->tree(['r/home.dom.html.gz' => gzencode("<p>b</p>\n", 9)]);

        self::assertSame(0, Comparison::run($left, $same, frontend: false)->differences);
        $result = Comparison::run($left, $other, frontend: false);
        self::assertSame(1, $result->differences);
        self::assertStringContainsString('-<p>a</p>', $result->report);
    }

    public function testARenderedDomIsNormalisedAndStrippedInFrontendMode(): void
    {
        $left = $this->tree(['r/home.dom.html.gz' => gzencode("<script nonce=\"aa\">x</script>\n<script src=\"/app.js?v=11111111\"></script>\n")]);
        $right = $this->tree(['r/home.dom.html.gz' => gzencode("<script nonce=\"bb\">x</script>\n<script type=\"module\" src=\"/js/22222222/app.js\"></script>\n")]);

        self::assertSame(1, Comparison::run($left, $right, frontend: false)->differences);
        self::assertSame(0, Comparison::run($left, $right, frontend: true)->differences);
    }

    public function testAStyleDumpIsComparedAsItIs(): void
    {
        // nothing in a style dump is a per-run value or an element to drop
        $left = $this->tree(['r/home.style.txt.gz' => gzencode("html::before | - | content:\"nonce=\"aa\"\"\n")]);
        $right = $this->tree(['r/home.style.txt.gz' => gzencode("html::before | - | content:\"nonce=\"bb\"\"\n")]);

        self::assertSame(1, Comparison::run($left, $right, frontend: false)->differences);
        self::assertSame(1, Comparison::run($left, $right, frontend: true)->differences);
    }

    public function testAStyleDumpDiffNamesTheClassOfEachChangedElement(): void
    {
        $dom = gzencode('<html><head></head><body><p class="a">x</p><p class="pl-tag">y</p></body></html>');
        $style = static fn(string $padding): string => gzencode(
            "html | 0,0,412,915 | \n"
            . "html > body:nth-of-type(1) | 0,0,412,915 | \n"
            . "html > body:nth-of-type(1) > p:nth-of-type(1) | 0,0,10,10 | \n"
            . 'html > body:nth-of-type(1) > p:nth-of-type(2) | 0,10,10,10 | padding-left:' . $padding . "\n"
            . "html > body:nth-of-type(1) > p:nth-of-type(3)::before | - | content:\"x\"\n",
        );
        $left = $this->tree(['r/home.style.txt.gz' => $style('8px'), 'r/home.dom.html.gz' => $dom]);
        $right = $this->tree(['r/home.style.txt.gz' => $style('9px'), 'r/home.dom.html.gz' => $dom]);

        $report = Comparison::run($left, $right, frontend: false)->report;
        self::assertStringContainsString('padding-left:8px  [class="pl-tag"]', $report);
        self::assertStringContainsString('padding-left:9px  [class="pl-tag"]', $report);
        // a context line is left as it is
        self::assertStringNotContainsString('0,0,10,10 |   [class=', $report);
    }

    public function testFrontendModeIgnoresAChangedScriptTag(): void
    {
        $left = $this->tree(['x/01-a.txt' => "<p>a</p>\n<script src=\"/app.js?v=11111111\"></script>\n"]);
        $right = $this->tree(['x/01-a.txt' => "<p>a</p>\n<script type=\"module\" src=\"/js/22222222/app.js\"></script>\n"]);

        self::assertSame(1, Comparison::run($left, $right, frontend: false)->differences);
        self::assertSame(0, Comparison::run($left, $right, frontend: true)->differences);
    }
}
