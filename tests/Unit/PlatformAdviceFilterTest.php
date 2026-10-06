<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Push\PlatformAdviceFilter;
use PHPUnit\Framework\TestCase;

final class PlatformAdviceFilterTest extends TestCase
{
    public function testOnlyTheGmpAdviceIsDropped(): void
    {
        $inner = new RecordingLogger();
        $filter = new PlatformAdviceFilter($inner);

        $filter->notice('It is highly recommended to install the GMP or BCMath extension to speed up calculations. The fastest available calculator implementation will be automatically selected at runtime.');
        $filter->warning('[WebPush] Openssl does not support required curve prime256v1.', ['x' => 1]);
        $filter->notice('something else');

        self::assertSame([
            ['level' => 'warning', 'message' => '[WebPush] Openssl does not support required curve prime256v1.', 'context' => ['x' => 1]],
            ['level' => 'notice', 'message' => 'something else', 'context' => []],
        ], $inner->records);
    }
}
