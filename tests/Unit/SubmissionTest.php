<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Inbox\Kind;
use App\Inbox\Submission;
use PHPUnit\Framework\TestCase;

final class SubmissionTest extends TestCase
{
    public function testFromFormTrimsAndNormalisesLineEndings(): void
    {
        $submission = Submission::fromForm(['kind' => 'help', 'body' => "  a\r\nb\r  ", 'contact' => '  kaja@example.cz ']);

        self::assertSame(Kind::Help, $submission->kind);
        self::assertSame("a\nb", $submission->body);
        self::assertSame('kaja@example.cz', $submission->contact);

        self::assertSame("a\nb\nc", Submission::fromForm(['body' => "a\rb\r\nc"])->body);
    }

    public function testAnUnknownOrMissingKindIsNull(): void
    {
        self::assertNull(Submission::fromForm(['kind' => 'x'])->kind);
        self::assertNull(Submission::fromForm(['kind' => ['help']])->kind);
        self::assertNull(Submission::fromForm([])->kind);
        self::assertSame(Kind::Idea, Submission::fromForm(['kind' => 'idea'])->kind);
    }

    public function testErrorsInTheSpecsOrder(): void
    {
        self::assertSame(['Vyber, o co jde.', 'Napiš zprávu.'], Submission::empty()->errors());
        self::assertSame(['Vyber, o co jde.', 'Napiš zprávu.'], Submission::fromForm(['body' => " \r\n ", 'contact' => ''])->errors());
        self::assertSame([], Submission::fromForm(['kind' => 'idea', 'body' => 'Díky!'])->errors());
    }

    public function testTheTextLimitCountsCharactersNotBytes(): void
    {
        $limit = str_repeat('ž', 999) . '🙂';
        self::assertSame([], Submission::fromForm(['kind' => 'help', 'body' => $limit])->errors());
        self::assertSame(
            ['Zpráva smí mít nejvýš 1000 znaků.'],
            Submission::fromForm(['kind' => 'help', 'body' => $limit . 'a'])->errors(),
        );

        // a browser sends a textarea's line breaks as \r\n; each counts as one character
        $lines = str_repeat('a', 499) . "\r\n" . str_repeat('b', 500);
        self::assertSame([], Submission::fromForm(['kind' => 'help', 'body' => $lines])->errors());
    }

    public function testTheContactLimit(): void
    {
        self::assertSame([], Submission::fromForm(['kind' => 'help', 'body' => 'Ahoj', 'contact' => str_repeat('č', 100)])->errors());
        self::assertSame(
            ['Kontakt smí mít nejvýš 100 znaků.'],
            Submission::fromForm(['kind' => 'help', 'body' => 'Ahoj', 'contact' => str_repeat('a', 101)])->errors(),
        );
    }
}
