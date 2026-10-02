<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\EventConfig;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

final class KernelTwigTest extends TestCase
{
    /**
     * Twig's cache key is the template name, not its source. Without auto_reload an edited
     * template keeps being served from var/twig until somebody deletes it, so a test run
     * would pass against templates that are no longer on disk.
     */
    public function testTheCompiledTemplateCacheIsRevalidatedAgainstTheSource(): void
    {
        $app = Kernel::create(EventConfig::load(dirname(__DIR__, 2) . '/events', 'obrok19'));
        $env = $app->getContainer()->get(Twig::class)->getEnvironment();

        self::assertTrue($env->isAutoReload());
    }

    /**
     * The behaviour the flag buys, shown on a scratch template. Each render runs in its own
     * PHP process, because within one process Twig reuses the class it already declared.
     */
    public function testAnEditedTemplateIsRecompiledOnlyWithAutoReload(): void
    {
        $dir = sys_get_temp_dir() . '/twig-reload-' . uniqid();
        mkdir($dir . '/tpl', 0777, true);
        $file = $dir . '/tpl/t.twig';
        $script = $dir . '/render.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . 'echo (new Twig\\Environment(new Twig\\Loader\\FilesystemLoader(' . var_export($dir . '/tpl', true) . '), ['
            . "'cache' => " . var_export($dir . '/cache', true) . ", 'auto_reload' => (bool) \$argv[1]]))->render('t.twig');");
        $render = static fn (string $reload): string => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $reload);

        file_put_contents($file, 'one');
        self::assertSame('one', $render('1'));
        file_put_contents($file, 'two');
        touch($file, time() + 10);

        self::assertSame('one', $render('0'), 'without auto_reload the stale copy is served');
        self::assertSame('two', $render('1'));

        exec('rm -rf ' . escapeshellarg($dir));
    }
}
