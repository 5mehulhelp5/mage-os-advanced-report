<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Dimension::class)]
final class DimensionTest extends TestCase
{
    public function testItExposesAllowedGranularitiesOnlyForDateDimensions(): void
    {
        $dateDimension = new Dimension(
            code: 'created_at',
            label: 'Date',
            fieldCode: 'created_at',
            granularities: [Granularity::DAY, Granularity::MONTH],
        );
        $categoryDimension = new Dimension(
            code: 'channel',
            label: 'Channel',
            fieldCode: 'channel',
        );

        self::assertSame([Granularity::DAY, Granularity::MONTH], $dateDimension->getGranularities());
        self::assertSame([], $categoryDimension->getGranularities());
    }
}
