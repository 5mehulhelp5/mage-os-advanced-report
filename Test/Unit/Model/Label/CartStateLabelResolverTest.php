<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use MageOS\AdvancedReport\Model\Label\CartStateLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CartStateLabelResolver::class)]
final class CartStateLabelResolverTest extends TestCase
{
    public function testItLabelsEachCartStateValue(): void
    {
        $this->assertSame(
            [
                'active' => 'Active',
                'abandoned' => 'Abandoned',
                'unrecoverable' => 'Abandoned without email',
                'converted' => 'Converted',
                'inactive' => 'Inactive',
            ],
            (new CartStateLabelResolver())->resolve(['active', 'abandoned', 'unrecoverable', 'converted', 'inactive'])
        );
    }

    public function testItReturnsTranslatedLabels(): void
    {
        $this->assertSame(
            ['unrecoverable' => (string)__('Abandoned without email')],
            (new CartStateLabelResolver())->resolve(['unrecoverable'])
        );
    }

    public function testItKeepsUnknownValuesUnchanged(): void
    {
        $this->assertSame(['bogus' => 'bogus'], (new CartStateLabelResolver())->resolve(['bogus']));
    }

    public function testItIgnoresNullValues(): void
    {
        $values = array_map(static fn (): string => (string)null, [null]);

        $this->assertSame(['' => (string)__('(none)')], (new CartStateLabelResolver())->resolve($values));
    }
}
