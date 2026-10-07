<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Session;
use PHPUnit\Framework\TestCase;

/**
 * Where the session lives: the files handler in var/sessions, else in a private directory
 * under the system temp dir, else the host's own handler and path. "Unusable" is built as a
 * path under a regular file, which no mkdir can create — unlike a chmod, that holds for root
 * too, and the tests run as root in Docker. The temp dir is a scratch directory here.
 */
final class SessionStorageTest extends TestCase
{
    private const string ROOT = '/srv/app';

    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/session-storage-test-' . bin2hex(random_bytes(4));
        mkdir($this->scratch);
        touch($this->scratch . '/a-file');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch . '/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                rmdir($path);
            }
        }
        rmdir($this->scratch);
    }

    public function testAUsablePreferredDirectoryIsCreatedPrivateAndChosen(): void
    {
        $preferred = $this->scratch . '/preferred';

        self::assertSame($preferred, Session::storage($preferred, self::ROOT, $this->scratch));
        self::assertSame(0o700, fileperms($preferred) & 0o777);
        self::assertDirectoryDoesNotExist($this->fallback());
    }

    public function testAnUnusableVarFallsBackToAPrivateTempDirectory(): void
    {
        self::assertSame($this->fallback(), Session::storage($this->unusable(), self::ROOT, $this->scratch));
        self::assertSame(0o700, fileperms($this->fallback()) & 0o777);
    }

    public function testAnExistingPrivateFallbackIsReused(): void
    {
        mkdir($this->fallback(), 0o700);

        self::assertSame($this->fallback(), Session::storage($this->unusable(), self::ROOT, $this->scratch));
    }

    public function testWhenNeitherIsUsableTheHostsStoreIsKept(): void
    {
        self::assertNull(Session::storage($this->unusable(), self::ROOT, $this->scratch . '/a-file'));
    }

    public function testWithoutAnAppRootThereIsNoFallback(): void
    {
        self::assertNull(Session::storage($this->unusable(), null, $this->scratch));
    }

    /** Review N1: a world-readable directory lists every session ID */
    public function testAFallbackOthersCanReadIsRefused(): void
    {
        mkdir($this->fallback());
        chmod($this->fallback(), 0o777);

        self::assertNull(Session::storage($this->unusable(), self::ROOT, $this->scratch));
    }

    /** Review N1: a symlink planted in the shared temp dir points the store wherever its owner likes */
    public function testAFallbackThatIsASymlinkIsRefused(): void
    {
        mkdir($this->scratch . '/elsewhere', 0o700);
        symlink($this->scratch . '/elsewhere', $this->fallback());

        self::assertNull(Session::storage($this->unusable(), self::ROOT, $this->scratch));
    }

    public function testAPreferredDirectoryThatIsASymlinkIsRefused(): void
    {
        mkdir($this->scratch . '/elsewhere', 0o700);
        symlink($this->scratch . '/elsewhere', $this->scratch . '/preferred');

        self::assertSame($this->fallback(), Session::storage($this->scratch . '/preferred', self::ROOT, $this->scratch));
    }

    /** Review m2: SESSION_PATH may name a shared directory, where others could list the session IDs */
    public function testAPreferredDirectoryOutsideTheAppThatOthersCanReadIsRefused(): void
    {
        $preferred = $this->scratch . '/shared';
        mkdir($preferred);
        chmod($preferred, 0o777);

        self::assertSame($this->fallback(), Session::storage($preferred, self::ROOT, $this->scratch));
    }

    /** var/sessions inside the app tree keeps whatever mode the deploy gave it */
    public function testAPreferredDirectoryInsideTheAppNeedsNoPrivacy(): void
    {
        $root = $this->scratch . '/app';
        mkdir($root . '/var/sessions', 0o777, true);
        chmod($root . '/var/sessions', 0o777);

        self::assertSame($root . '/var/sessions', Session::storage($root . '/var/sessions', $root, $this->scratch));

        foreach ([$root . '/var/sessions', $root . '/var', $root] as $dir) {
            rmdir($dir);
        }
    }

    /** a path that only spells the app root, then climbs out of it, is outside */
    public function testAPreferredDirectoryThatClimbsOutOfTheAppIsOutside(): void
    {
        $root = $this->scratch . '/app';
        mkdir($root);
        mkdir($this->scratch . '/shared');
        chmod($this->scratch . '/shared', 0o777);

        self::assertSame(
            Session::fallbackPath($root, $this->scratch),
            Session::storage($root . '/../shared', $root, $this->scratch),
        );

        rmdir(Session::fallbackPath($root, $this->scratch));
        rmdir($root);
    }

    public function testTheFallbackIsOnePerCheckout(): void
    {
        $path = Session::fallbackPath(self::ROOT);

        self::assertSame(sys_get_temp_dir() . '/eventapp-sessions-' . substr(hash('sha256', self::ROOT), 0, 8), $path);
        self::assertNotSame($path, Session::fallbackPath('/srv/other'));
    }

    public function testTheSessionFileSlidesOnlyOnceADay(): void
    {
        $file = $this->scratch . '/sess_x';
        $now = time();
        touch($file, $now - 3600);
        self::assertFalse(Session::slide($file, $now), 'touched within the day: left alone');

        touch($file, $now - 2 * 86400);
        self::assertTrue(Session::slide($file, $now));
        clearstatcache(true, $file);
        self::assertSame($now, filemtime($file));

        self::assertFalse(Session::slide($this->scratch . '/missing', $now), 'a missing file is not created');
        self::assertFileDoesNotExist($this->scratch . '/missing');
    }

    private function fallback(): string
    {
        return Session::fallbackPath(self::ROOT, $this->scratch);
    }

    private function unusable(): string
    {
        return $this->scratch . '/a-file/sessions';
    }
}
