<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use MageOS\AdvancedReport\Model\Label\WeekdayLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WeekdayLabelResolver::class)]
final class EmptyAndUnknownLabelResolverTest extends TestCase
{
    public function testItLabelsEmptyValuesAsNoneAndUnknownValuesAsRaw(): void
    {
        $labels = (new WeekdayLabelResolver())->resolve(['', '1', '0', '9']);

        $this->assertSame(['' => '(none)', '1' => 'Monday', '0' => 'Sunday', '9' => '9'], $labels);
    }
}
