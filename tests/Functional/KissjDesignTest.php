<?php

declare(strict_types=1);

namespace Tests\Functional;

/** The three events that borrow kissj's look carry kissj's colours, in both modes. */
final class KissjDesignTest extends AppTestCase
{
    /** @return iterable<string, array{string, string, string}> slug, light event colour, dark event colour */
    public static function events(): iterable
    {
        yield 'korbo26' => ['korbo26', '#dd6700', '#dd6700'];
        // the 2027 identity: kissj's purple, inverted to white for the dark selected state
        yield 'navigamus27' => ['navigamus27', '#1b0e44', '#ffffff'];
        yield 'miquik26' => ['miquik26', '#0D71B9', '#85A2D5'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('events')]
    public function testTheEventColourIsKissjsInBothModes(string $slug, string $light, string $dark): void
    {
        $event = \App\EventConfig::load($this->eventsDir(), $slug);

        self::assertSame($light, $event->roles->light['state']);
        self::assertSame($dark, $event->roles->dark['state']);
        self::assertSame('6px', $event->theme['radius']);
    }

    /** @return iterable<string, array{string}> */
    public static function slugs(): iterable
    {
        foreach (self::events() as $slug => $row) {
            yield $slug => [$row[0]];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('slugs')]
    public function testTheModeControlIsOffered(string $slug): void
    {
        $app = $this->createApp($slug);
        $this->pair($app);
        $html = (string) $this->request($app, 'GET', '/profil')->getBody();

        // the control only renders for an event that declares a dark set, on Profil paired or not
        self::assertStringContainsString('<fieldset class="mode-control" data-mode-control>', $html);
    }

    public function testKissjLogosAreShipped(): void
    {
        // navigamus27's home tab shows the house icon: its N27 mark is on the app bar instead
        foreach (['korbo26' => ['menuLogo', 'mainLogo'], 'navigamus27' => ['appBarLogo', 'mainLogo', 'mainLogoDark']] as $slug => $keys) {
            $assets = \App\EventConfig::load($this->eventsDir(), $slug)->assets;
            foreach ($keys as $key) {
                self::assertFileExists(dirname(__DIR__, 2) . '/www/' . $assets->{$key}, $slug . ' ' . $key);
            }
        }
    }
}
