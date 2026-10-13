<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use MageOS\AdvancedReport\Model\Label\MonthLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonthLabelResolver::class)]
final class MonthLabelResolverTest extends TestCase
{
    public function testItLabelsMonthNumbersOneToTwelveWithMonthNames(): void
    {
        $resolver = new MonthLabelResolver();
        $names = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        $labels = $resolver->resolve(array_map(strval(...), range(1, 12)));

        $this->assertSame(array_combine(array_map(strval(...), range(1, 12)), $names), $labels);
    }

    public function testItKeepsAnUnknownMonthNumberRaw(): void
    {
        $resolver = new MonthLabelResolver();

        $this->assertSame(['13' => '13'], $resolver->resolve(['13']));
    }
}
