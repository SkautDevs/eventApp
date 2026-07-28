<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testPhpVersionFloor(): void
    {
        self::assertGreaterThanOrEqual(80300, PHP_VERSION_ID);
    }
}
