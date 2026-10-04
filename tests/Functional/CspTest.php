<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\EventCatalog;
use App\EventConfig;
use App\Kernel;
use Psr\Http\Message\ResponseInterface;

final class CspTest extends AppTestCase
{
    /** The five tab destinations plus /profil. */
    private const SCREENS = ['/', '/programy', '/mapa', '/novinky', '/odkazy', '/profil'];

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_TOKEN_OBROK27']);
        parent::tearDown();
    }

    /** @return array<string, list<string>> directive => its values */
    private static function policy(ResponseInterface $response): array
    {
        $header = $response->getHeaderLine('Content-Security-Policy');
        self::assertNotSame('', $header, 'no Content-Security-Policy header');
        $directives = [];
        foreach (explode(';', $header) as $part) {
            $tokens = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens !== []) {
                $directives[array_shift($tokens)] = $tokens;
            }
        }

        return $directives;
    }

    private static function nonceOf(ResponseInterface $response): string
    {
        $nonces = array_values(array_filter(
            self::policy($response)['script-src'] ?? [],
            static fn (string $token): bool => str_starts_with($token, "'nonce-"),
        ));
        self::assertCount(1, $nonces, 'script-src must hold exactly one nonce');
        self::assertMatchesRegularExpression("/^'nonce-[0-9a-f]{32}'$/", $nonces[0]);

        return substr($nonces[0], 7, -1);
    }

    private static function assertEveryInlineScriptCarries(string $nonce, string $html, string $where): void
    {
        preg_match_all('~<script\b([^>]*)>~i', $html, $tags);
        foreach ($tags[1] as $attributes) {
            if (preg_match('~\bsrc\s*=~i', $attributes) === 1) {
                continue;
            }
            self::assertStringContainsString('nonce="' . $nonce . '"', $attributes, $where . ': an inline <script> without the nonce');
        }
        self::assertDoesNotMatchRegularExpression('~<[a-z][^>]*\son[a-z]+\s*=~i', $html, $where . ': an inline event handler');
    }

    public function testEveryScreenCarriesThePolicyAndItsScriptsTheNonce(): void
    {
        $app = $this->createApp('obrok27');

        foreach (self::SCREENS as $path) {
            $response = $this->request($app, 'GET', $path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertEveryInlineScriptCarries(self::nonceOf($response), (string) $response->getBody(), $path);
        }
    }

    public function testTheLayoutsTwoInlineScriptsAreNonced(): void
    {
        $response = $this->request($this->createApp('obrok27'), 'GET', '/');

        self::assertSame(2, substr_count((string) $response->getBody(), '<script nonce="' . self::nonceOf($response) . '">'));
    }

    public function testTheAdminPageCarriesItToo(): void
    {
        $_ENV['ADMIN_TOKEN_OBROK27'] = 'token';
        $app = $this->createApp('obrok27');
        $this->request($app, 'GET', '/admin/notify?token=token');

        $response = $this->request($app, 'GET', '/admin/notify');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        $nonce = self::nonceOf($response);
        self::assertSame(3, substr_count($html, '<script nonce="' . $nonce . '">'));
        self::assertEveryInlineScriptCarries($nonce, $html, '/admin/notify');
    }

    public function testANotFoundPageCarriesThePolicy(): void
    {
        $response = $this->request($this->createApp('obrok27'), 'GET', '/neexistuje');

        self::assertSame(404, $response->getStatusCode());
        self::nonceOf($response);
    }

    public function testTwoRequestsGetTwoNonces(): void
    {
        $app = $this->createApp('obrok27');

        self::assertNotSame(self::nonceOf($this->request($app, 'GET', '/')), self::nonceOf($this->request($app, 'GET', '/')));
    }

    public function testAScreenFragmentCarriesThePolicyWithAFreshNonce(): void
    {
        $app = $this->createApp('obrok27');

        $first = $this->request($app, 'GET', '/programy', headers: ['X-Screen' => '1']);
        $second = $this->request($app, 'GET', '/programy', headers: ['X-Screen' => '1']);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertTrue($first->hasHeader('Content-Security-Policy'));
        self::assertStringContainsString('<section', (string) $first->getBody());
        self::assertStringNotContainsString('<html', (string) $first->getBody());
        self::assertNotSame(self::nonceOf($first), self::nonceOf($second));
    }

    public function testTheBasePolicy(): void
    {
        $policy = self::policy($this->request($this->createApp('korbo26'), 'GET', '/'));

        self::assertSame(["'self'"], $policy['default-src']);
        self::assertSame("'self'", $policy['script-src'][0]);
        self::assertNotContains("'unsafe-inline'", $policy['script-src']);
        self::assertSame(["'self'", "'unsafe-inline'", 'https://use.fontawesome.com', 'https://cdn.skauting.cz'], $policy['style-src']);
        self::assertSame(["'self'", 'https://use.fontawesome.com', 'https://cdn.skauting.cz'], $policy['font-src']);
        self::assertSame(["'self'", 'data:'], $policy['img-src']);
        self::assertSame(["'self'"], $policy['connect-src']);
        self::assertSame(["'self'"], $policy['base-uri']);
        self::assertSame(["'self'"], $policy['form-action']);
        self::assertSame(["'none'"], $policy['frame-ancestors']);
        self::assertSame(["'none'"], $policy['object-src']);
        // korbo26 has no map and no webfont of its own
        self::assertArrayNotHasKey('frame-src', $policy);
        self::assertNotContains('https://fonts.gstatic.com', $policy['font-src']);
    }

    public function testObrok27AddsItsMapAndItsWebfont(): void
    {
        $policy = self::policy($this->request($this->createApp('obrok27'), 'GET', '/'));

        self::assertSame(['https://www.google.com'], $policy['frame-src']);
        self::assertContains('https://fonts.googleapis.com', $policy['style-src']);
        self::assertContains('https://fonts.gstatic.com', $policy['font-src']);
    }

    public function testAnEventsCspKeyIsMerged(): void
    {
        $policy = self::policy($this->request($this->createApp('csp', fixtureEvent: true), 'GET', '/'));

        self::assertSame(["'self'", 'data:', 'https://photos.example'], $policy['img-src']);
        self::assertSame(['https://video.example'], $policy['frame-src']);
    }

    public function testAnInvalidCspKeyFailsAtLoad(): void
    {
        $dir = sys_get_temp_dir() . '/csp-' . bin2hex(random_bytes(4));
        mkdir($dir . '/broken26', 0o777, true);
        $config = require dirname(__DIR__) . '/fixtures/events/csp/config.php';
        $config['csp'] = ['worker-src' => ['https://x.example']];
        file_put_contents($dir . '/broken26/config.php', '<?php return ' . var_export($config, true) . ';');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('csp');
            EventConfig::load($dir, 'broken26');
        } finally {
            unlink($dir . '/broken26/config.php');
            rmdir($dir . '/broken26');
            rmdir($dir);
        }
    }

    public function testThePickerCarriesThePolicyWithoutEventExtras(): void
    {
        $instance = Kernel::createInstance(new EventCatalog(dirname(__DIR__) . '/fixtures/events'), new \DateTimeImmutable('2026-09-30'));

        $response = $this->rawRequest($instance, 'GET', '/');

        self::assertSame(200, $response->getStatusCode());
        $policy = self::policy($response);
        self::nonceOf($response);
        self::assertArrayNotHasKey('frame-src', $policy);
        self::assertSame(["'self'", "'unsafe-inline'", 'https://use.fontawesome.com', 'https://cdn.skauting.cz'], $policy['style-src']);
    }

    public function testTheHomepageButtonIsBoundByPushJsNotInline(): void
    {
        $html = (string) $this->request($this->createApp('obrok27'), 'GET', '/')->getBody();

        self::assertStringContainsString('data-push-toggle', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringContainsString("querySelectorAll('[data-push-toggle]')", (string) file_get_contents(dirname(__DIR__, 2) . '/www/push.js'));
    }
}
