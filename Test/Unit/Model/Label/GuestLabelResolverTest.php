<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use MageOS\AdvancedReport\Model\Label\GuestLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuestLabelResolver::class)]
final class GuestLabelResolverTest extends TestCase
{
    public function testItLabelsTheGuestFlag(): void
    {
        $this->assertSame(
            ['1' => 'Guest', '0' => 'Registered customer'],
            (new GuestLabelResolver())->resolve(['1', '0'])
        );
    }

    public function testItKeepsUnknownValuesUnchanged(): void
    {
        $this->assertSame(['x' => 'x'], (new GuestLabelResolver())->resolve(['x']));
    }
}
