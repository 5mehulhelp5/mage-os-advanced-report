<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetRequestBuilder::class)]
final class PresetRequestBuilderTest extends TestCase
{
    public function testItBuildsAReportRequestFromAPresetAndDateRange(): void
    {
        $preset = new Preset(
            code: 'sales_over_time',
            area: 'sales',
            label: 'Sales over time',
            dataset: 'sales_orders',
            metrics: ['net_sales'],
            dimensions: [['code' => 'order_date', 'granularity' => Granularity::DAY]],
            filters: [['field' => 'status', 'operator' => FilterOperator::EQ, 'value' => 'complete']],
            sort: [['metric' => 'net_sales', 'direction' => 'desc']],
            compareTo: 'previous_period',
            dateRange: 'last_7_days',
            sources: ['mysql'],
        );

        $request = (new PresetRequestBuilder(new ReportRequestFactory()))->build($preset, [
            'dateRange' => ['type' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
        ]);

        self::assertSame('sales_over_time', $request->getPreset());
        self::assertSame('sales_orders', $request->getDataset());
        self::assertSame(['net_sales'], $request->getMetrics());
        self::assertSame('order_date', $request->getDimensions()[0]->code);
        self::assertSame(Granularity::DAY, $request->getDimensions()[0]->granularity);
        self::assertSame('complete', $request->getFilters()[0]->value);
        self::assertSame('net_sales', $request->getSort()[0]->by);
        self::assertSame(CompareMode::PREVIOUS_PERIOD, $request->getCompareTo());
        self::assertSame(DateRangeType::CUSTOM, $request->getDateRange()->type);
        self::assertSame('2026-01-31', $request->getDateRange()->to);
    }
}
