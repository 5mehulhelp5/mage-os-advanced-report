<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use InvalidArgumentException;
use MageOS\AdvancedReport\Api\LabelResolverInterface;
use MageOS\AdvancedReport\Model\Label\LabelResolverPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LabelResolverPool::class)]
final class LabelResolverPoolTest extends TestCase
{
    public function testItReturnsTheResolverRegisteredUnderTheName(): void
    {
        $resolver = $this->createStub(LabelResolverInterface::class);
        $pool = new LabelResolverPool(['Foo' => $resolver]);

        $this->assertTrue($pool->has('Foo'));
        $this->assertSame($resolver, $pool->get('Foo'));
    }

    public function testItThrowsForAnUnknownName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LabelResolverPool())->get('Nope');
    }
}
