<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\TwigErrorRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class TwigErrorRendererTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/error-renderer-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/probe.twig', "{{ notFound ? 'NF' : 'ERR' }}|{{ details ?? '' }}");
        file_put_contents($this->dir . '/broken.twig', '{{ notFound ? ');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testTheTemplateIsToldWhatKindOfErrorItIsAndGetsDetailsOnlyUnderDebug(): void
    {
        $renderer = new TwigErrorRenderer(Twig::create($this->dir), 'probe.twig');
        $notFound = new HttpNotFoundException((new ServerRequestFactory())->createServerRequest('GET', '/x'));

        self::assertSame('NF|', $renderer($notFound, false));
        self::assertSame('ERR|', $renderer(new \RuntimeException('kaboom'), false));
        $debug = $renderer(new \RuntimeException('kaboom'), true);
        self::assertStringStartsWith('ERR|RuntimeException', $debug);
        self::assertStringContainsString('kaboom', $debug);
    }

    /** Review Focus 3: an error page that cannot render is still a page. */
    public function testABrokenTemplateFallsBackToAPlainCzechPage(): void
    {
        $renderer = new TwigErrorRenderer(Twig::create($this->dir), 'broken.twig');

        $html = $renderer(new \RuntimeException('kaboom'), true);

        self::assertSame(TwigErrorRenderer::FALLBACK, $html);
        self::assertStringContainsString('Něco se pokazilo. Zkus to za chvíli.', $html);
        // the page a phone reader sees when things are worst is laid out for the phone
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        self::assertStringNotContainsString('kaboom', $html);
    }
}
