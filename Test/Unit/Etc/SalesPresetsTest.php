<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\Preset;
use PHPUnit\Framework\TestCase;

final class SalesPresetsTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    private const array SALES_PRESETS = [
        'sales_overview',
        'sales_by_payment_method',
        'sales_by_shipping_method',
        'sales_by_shipping_country',
        'sales_by_customer_group',
        'new_vs_returning',
        'sales_by_hour',
        'sales_by_weekday',
        'coupon_performance',
    ];

    public function testItDeclaresAllSalesPresetsInTheSalesArea(): void
    {
        $presets = $this->loadReportPresets();

        foreach (self::SALES_PRESETS as $code) {
            self::assertArrayHasKey($code, $presets);
            self::assertSame('sales', $presets[$code]->area);
            self::assertSame('sales_orders', $presets[$code]->dataset);
            self::assertSame('last_30_days', $presets[$code]->dateRange);
        }
    }

    public function testItDeclaresSalesOverviewByDayWithPreviousPeriodComparison(): void
    {
        $preset = $this->loadReportPresets()['sales_overview'];
        $dataset = $this->loadReportDataset('sales_orders');

        $visible = array_keys(array_filter($dataset->metrics, static fn (Metric $metric): bool => !$metric->hidden));
        self::assertEqualsCanonicalizing($visible, $preset->metrics);
        self::assertSame('order_date', $preset->dimensions[0]['code']);
        self::assertSame('day', $preset->dimensions[0]['granularity']?->value);
        self::assertSame('previous_period', $preset->compareTo);
    }

    public function testItDeclaresCouponPerformanceFilteredToOrdersWithACoupon(): void
    {
        $preset = $this->loadReportPresets()['coupon_performance'];

        self::assertSame(['orders', 'discounts', 'net_sales'], $preset->metrics);
        self::assertSame('coupon_code', $preset->dimensions[0]['code']);
        self::assertCount(1, $preset->filters);
        self::assertSame('has_coupon', $preset->filters[0]['field']);
        self::assertSame(FilterOperator::EQ, $preset->filters[0]['operator']);
        self::assertContains($preset->filters[0]['value'], [1, '1', true, ['1'], [1], [true]]);
    }

    public function testItPassesDefinitionValidationForEverySalesPreset(): void
    {
        // The fixture validates in developer mode (throws on any error); presets must survive.
        self::assertSame(
            [],
            array_values(array_diff(self::SALES_PRESETS, array_keys($this->loadReportPresets())))
        );
    }

    public function testItGivesEveryPresetALabel(): void
    {
        foreach ($this->loadReportPresets() as $preset) {
            /** @var Preset $preset */
            self::assertNotSame('', trim($preset->label), $preset->code);
        }
    }
}
