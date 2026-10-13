<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Metric;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Metric::class)]
final class MetricTest extends TestCase
{
    public function testItDistinguishesAggregateDerivedAndVirtualMetrics(): void
    {
        $aggregate = new Metric(
            code: 'net_sales',
            label: 'Net Sales',
            description: null,
            format: 'money',
            hidden: false,
            lowerIsBetter: false,
            aggregate: AggregateFunction::SUM,
            fieldCode: 'base_subtotal',
        );
        $derived = new Metric(
            code: 'return_rate',
            label: 'Return Rate',
            description: null,
            format: 'percent',
            hidden: false,
            lowerIsBetter: true,
            formula: 'returns / gross_sales',
        );
        $virtual = new Metric(
            code: 'coverage_days',
            label: 'Coverage Days',
            description: null,
            format: 'number',
            hidden: false,
            lowerIsBetter: false,
            virtual: true,
        );

        self::assertTrue($aggregate->isAggregate());
        self::assertFalse($aggregate->isDerived());
        self::assertFalse($aggregate->isVirtual());

        self::assertFalse($derived->isAggregate());
        self::assertTrue($derived->isDerived());
        self::assertFalse($derived->isVirtual());

        self::assertFalse($virtual->isAggregate());
        self::assertFalse($virtual->isDerived());
        self::assertTrue($virtual->isVirtual());
    }
}
