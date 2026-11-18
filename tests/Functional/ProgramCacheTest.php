<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Cache\FileCache;
use App\Program\CachingProgramProvider;
use App\Program\Freshness;
use App\Program\ProgramProviderInterface;
use App\Program\LocalProgramProvider;
use App\Program\Programme;
use App\Program\Section;
use Psr\Container\ContainerInterface;
use Slim\App;
use Tests\FixedClock;

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
            $stub = new LocalProgramProvider(dirname(__DIR__, 2) . '/events/obrok19/fixtures', $this->stubState);
            $written = new \DateTimeImmutable('2026-10-01 09:15:00', new \DateTimeZone('Europe/Prague'));
            (new FileCache($this->dir, new FixedClock($written)))
                ->set(CachingProgramProvider::LIST_KEY, [
                    // the entry as the decorator stores it: rows, sections keyed by id
                    'sections' => array_map(static fn(Section $section): array => $section->toArray(), $stub->getSections()),
                    'programmes' => array_map(static fn(Programme $programme): array => $programme->toArray(), $stub->getPrograms()),
                    // obrok19 has no registration.json: no window
                    'registration' => ['start' => null, 'end' => null],
                ]);
        }
        $down = new ThrowingProgramProvider(programsException: new \App\Program\ProgramUnavailableException('down'));

        return $this->createApp(overrides: [
            ProgramProviderInterface::class => fn(ContainerInterface $c): ProgramProviderInterface => new CachingProgramProvider(
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

        self::assertStringContainsString('Programy se nepodařilo načíst, zkus to prosím později.', $html);
        self::assertStringNotContainsString('data-stale', $html);
        self::assertSame([], $this->reported);
    }

    /**
     * Carry-over: with nothing cached the screen carries the request time and no data-stale
     * beside the outage notice, so the network banner says nothing while online. A stale
     * entry marks the section data-stale, which the banner reads ("Nepodařilo se načíst
     * aktuální data").
     */
    public function testAnOutageWithoutAnEntryIsNotMarkedStale(): void
    {
        foreach ([[], ['X-Screen' => '1']] as $headers) {
            $html = (string) $this->request($this->appDuringAnOutage(withEntry: false), 'GET', '/programy', null, $headers)->getBody();
            self::assertStringContainsString('Programy se nepodařilo načíst', $html);
            self::assertStringNotContainsString('data-stale', $html);
            self::assertStringNotContainsString('freshness', $html);
        }

        foreach ([[], ['X-Screen' => '1']] as $headers) {
            $stale = (string) $this->request($this->appDuringAnOutage(), 'GET', '/programy', null, $headers)->getBody();
            self::assertMatchesRegularExpression('~<section class="screen"[^>]*\sdata-stale="1"[^>]*>~', $stale);
        }
    }
}
