<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Cache\FileCache;
use App\Program\CachingProgramProvider;
use App\Program\Freshness;
use App\Program\ProgramProviderInterface;
use App\Program\StubProgramProvider;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Psr\Container\ContainerInterface;
use Slim\App;

/** The decorator end to end: a kissj outage behind a stale entry, as a reader sees it. */
final class ProgramCacheTest extends AppTestCase
{
    private string $dir;

    /** @var list<\Throwable> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/program-cache-' . bin2hex(random_bytes(4));
        $this->reported = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** obrok19's own programme, cached on 1 October at 09:15, and a kissj that no longer answers. */
    private function appDuringAnOutage(bool $withEntry = true): App
    {
        if ($withEntry) {
            $stub = new StubProgramProvider(dirname(__DIR__, 2) . '/events/obrok19/fixtures');
            $written = new \DateTimeImmutable('2026-10-01 09:15:00', new \DateTimeZone('Europe/Prague'));
            (new FileCache($this->dir, static fn (): \DateTimeImmutable => $written))
                ->set(CachingProgramProvider::LIST_KEY, ['sections' => $stub->getSections(), 'programmes' => $stub->getPrograms()]);
        }
        $down = new ThrowingProgramProvider(programsException: new ConnectException('down', new Request('GET', 'v3/programme/list')));

        return $this->createApp(overrides: [
            ProgramProviderInterface::class => fn (ContainerInterface $c): ProgramProviderInterface => new CachingProgramProvider(
                inner: $down,
                cache: new FileCache($this->dir),
                freshness: $c->get(Freshness::class),
                ttl: 300,
                report: function (\Throwable $e): void {
                    $this->reported[] = $e;
                },
            ),
        ]);
    }

    public function testAStaleEntryIsServedWithoutTheOutageNoticeAndMarkedStale(): void
    {
        $response = $this->request($this->appDuringAnOutage(), 'GET', '/programy');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Ukázková vycházka', $html);
        self::assertStringNotContainsString('Programy se nepodařilo načíst', $html);
        self::assertMatchesRegularExpression('/<section class="screen"[^>]*\sdata-stale="1"/', $html);
        self::assertCount(1, $this->reported, 'Sentry sees kissj failing while the reader sees nothing wrong');
    }

    public function testTheTimestampIsTheEntrysFetchTimeInPrague(): void
    {
        foreach ([[], ['X-Screen' => '1']] as $headers) {
            $html = (string) $this->request($this->appDuringAnOutage(), 'GET', '/programy', null, $headers)->getBody();

            self::assertStringContainsString('data-fetched-at="2026-10-01T09:15:00+02:00"', $html);
        }
    }

    public function testTheNextRequestDoesNotInheritTheStaleFlag(): void
    {
        $app = $this->appDuringAnOutage();
        $this->request($app, 'GET', '/programy');

        // the same container, as in a long-lived app: the homepage used no provider data
        $html = (string) $this->request($app, 'GET', '/')->getBody();

        self::assertStringNotContainsString('data-stale', $html);
        self::assertStringNotContainsString('data-fetched-at="2026-10-01T09:15:00+02:00"', $html);
    }

    public function testWithoutAnEntryTheOutageNoticeStillShows(): void
    {
        $html = (string) $this->request($this->appDuringAnOutage(withEntry: false), 'GET', '/programy')->getBody();

        self::assertStringContainsString('Programy se nepodařilo načíst, zkuste to prosím později.', $html);
        self::assertStringNotContainsString('data-stale', $html);
        self::assertSame([], $this->reported);
    }

    /**
     * Carry-over: with nothing cached the screen carries the request time and no data-stale
     * beside the outage notice — any freshness wording would be a lie, so there is none.
     * A stale entry keeps the line ("naposledy načteno", from its data-stale).
     */
    public function testAnOutageWithoutAnEntryCarriesNoFreshnessLine(): void
    {
        foreach ([[], ['X-Screen' => '1']] as $headers) {
            $html = (string) $this->request($this->appDuringAnOutage(withEntry: false), 'GET', '/programy', null, $headers)->getBody();
            self::assertStringContainsString('Programy se nepodařilo načíst', $html);
            self::assertStringNotContainsString('data-freshness', $html);
        }

        $stale = (string) $this->request($this->appDuringAnOutage(), 'GET', '/programy')->getBody();
        self::assertStringContainsString('<p class="freshness" data-freshness hidden></p>', $stale);
    }
}
