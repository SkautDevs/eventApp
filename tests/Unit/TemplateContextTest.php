<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Module\Map\View\MapPage;
use App\View\TemplateContext;
use PHPUnit\Framework\TestCase;

final class TemplateContextTest extends TestCase
{
    public function testAPageIsItsPublicPropertiesByName(): void
    {
        $page = new MapPage('google', 'https://www.google.com/maps/d/embed?mid=x', null, 'Plán areálu');

        self::assertSame(get_object_vars($page), TemplateContext::of($page));
        self::assertSame(['view' => 'google', 'embedUrl' => 'https://www.google.com/maps/d/embed?mid=x', 'image' => null, 'imageAlt' => 'Plán areálu'], TemplateContext::of($page));
    }
}
