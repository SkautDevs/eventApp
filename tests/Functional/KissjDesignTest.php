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
        yield 'navigamus25' => ['navigamus25', '#204c68', '#57A0AD'];
        yield 'miquik26' => ['miquik26', '#0D71B9', '#85A2D5'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('events')]
    public function testTheEventColourIsKissjsInBothModes(string $slug, string $light, string $dark): void
    {
        $event = \App\EventConfig::load($this->eventsDir(), $slug);

        self::assertSame($light, $event->roles['light']['state']);
        self::assertSame($dark, $event->roles['dark']['state']);
        self::assertSame('6px', $event->theme['radius']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('events')]
    public function testTheModeToggleIsOffered(string $slug): void
    {
        $html = (string) $this->request($this->createApp($slug), 'GET', '/')->getBody();

        // the button only renders for an event that declares a dark set
        self::assertStringContainsString('<button type="button" class="appbar-mode" data-mode-toggle', $html);
    }

    public function testKissjLogosAreShipped(): void
    {
        foreach (['korbo26', 'navigamus25'] as $slug) {
            $assets = \App\EventConfig::load($this->eventsDir(), $slug)->get('assets');
            foreach (['menuLogo', 'mainLogo'] as $key) {
                self::assertFileExists(dirname(__DIR__, 2) . '/www/' . $assets[$key], $slug . ' ' . $key);
            }
        }
    }
}
