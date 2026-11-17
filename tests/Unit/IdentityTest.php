<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Identity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function initials(): iterable
    {
        yield 'two words' => ['Jana Nováková', 'JN'];
        yield 'one word' => ['jana', 'JA'];
        yield 'one letter' => ['J', 'J'];
        yield 'no nickname' => [null, null];
        yield 'diacritics are upper-cased too' => ['šárka', 'ŠÁ'];
        yield 'blank is no nickname' => ['  ', null];
    }

    #[DataProvider('initials')]
    public function testInitials(?string $nickname, ?string $expected): void
    {
        self::assertSame($expected, (new Identity(1, $nickname, 'token'))->initials());
    }

    public function testTheDisplayNameIsTheNicknameOrAGenericOne(): void
    {
        self::assertSame('Jana Nováková', (new Identity(1, 'Jana Nováková', 'token'))->displayName());
        self::assertSame('Účastník', (new Identity(1, null, 'token'))->displayName());
        self::assertSame('Účastník', (new Identity(1, '', 'token'))->displayName());
    }

    public function testItSurvivesTheSessionAsAnArray(): void
    {
        $identity = new Identity(4711, 'Jana', 'stub-4711-0123456789abcdef');

        self::assertSame(['participantId' => 4711, 'nickname' => 'Jana', 'sessionToken' => 'stub-4711-0123456789abcdef'], $identity->toArray());
        self::assertEquals($identity, Identity::fromArray($identity->toArray()));
        self::assertEquals(new Identity(1, null, 't'), Identity::fromArray(['participantId' => 1, 'nickname' => null, 'sessionToken' => 't']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function notAnIdentity(): iterable
    {
        yield 'no session token' => [['participantId' => 1, 'nickname' => 'Jana']];
        yield 'an empty session token' => [['participantId' => 1, 'nickname' => 'Jana', 'sessionToken' => '']];
        yield 'participant id 0' => [['participantId' => 0, 'nickname' => 'Jana', 'sessionToken' => 't']];
        yield 'a negative participant id' => [['participantId' => -1, 'nickname' => 'Jana', 'sessionToken' => 't']];
        yield 'a participant id as a string' => [['participantId' => '1', 'nickname' => 'Jana', 'sessionToken' => 't']];
        yield 'an identity from before pairing' => [['displayName' => 'Účastník', 'code' => 'X1']];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('notAnIdentity')]
    public function testAnythingElseIsNoIdentity(array $data): void
    {
        self::assertNull(Identity::fromArray($data));
    }
}
