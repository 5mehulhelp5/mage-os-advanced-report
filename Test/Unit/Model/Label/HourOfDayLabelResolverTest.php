<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use MageOS\AdvancedReport\Model\Label\HourOfDayLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HourOfDayLabelResolver::class)]
final class HourOfDayLabelResolverTest extends TestCase
{
    public function testItFormatsHoursAsHhMm(): void
    {
        $resolver = new HourOfDayLabelResolver();

        $this->assertSame(
            ['7' => '07:00', '0' => '00:00', '23' => '23:00', '30' => '30'],
            $resolver->resolve(['7', '0', '23', '30'])
        );
    }
}
