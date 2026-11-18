<?php

declare(strict_types=1);

namespace Tests\Functional;

use Slim\Views\Twig;

/**
 * templates/_toast.twig: the server's half of the one toast component. www/js/shell/toast.js builds
 * the same markup for the toasts it shows itself (the offline one), so the two must agree.
 */
final class ToastTest extends AppTestCase
{
    private function render(array $toast): string
    {
        return $this->createApp('korbo26')->getContainer()->get(Twig::class)->fetch('_toast.twig', $toast);
    }

    public function testATitleAlone(): void
    {
        self::assertSame(
            '<div class="toast" role="status" data-toast data-key="toast"><p><strong>Jsi přihlášen</strong></p><button type="button" class="toast-close" data-toast-close aria-label="Zavřít">✕</button></div>',
            trim($this->render(['title' => 'Jsi přihlášen', 'line' => null])),
        );
    }

    public function testATitleAndASecondLine(): void
    {
        self::assertSame(
            '<div class="toast" role="status" data-toast data-key="toast"><p><strong>Odhlášeno</strong><span>Obsah teď můžeš jen prohlížet</span></p><button type="button" class="toast-close" data-toast-close aria-label="Zavřít">✕</button></div>',
            trim($this->render(['title' => 'Odhlášeno', 'line' => 'Obsah teď můžeš jen prohlížet'])),
        );
    }

    public function testTheTextIsEscaped(): void
    {
        $html = $this->render(['title' => '<b>x</b>', 'line' => '<i>y</i>']);

        self::assertStringContainsString('<strong>&lt;b&gt;x&lt;/b&gt;</strong><span>&lt;i&gt;y&lt;/i&gt;</span>', $html);
    }

    /** www/js/shell/toast.js builds the same element: the class names, the data attributes and the label. */
    public function testTheShellBuildsTheSameMarkup(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/www/js/shell/toast.js');

        foreach (["toast.className = 'toast';", "toast.setAttribute('role', 'status');", "toast.setAttribute('data-toast', '');", "toast.setAttribute('data-key', 'toast');", "close.className = 'toast-close';", "close.setAttribute('data-toast-close', '');", "close.setAttribute('aria-label', 'Zavřít');", "close.textContent = '✕';"] as $needle) {
            self::assertStringContainsString($needle, $js);
        }
    }
}
