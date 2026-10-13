<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\BaseCurrencyChecker;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\ReportWarningsCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportWarningsCollector::class)]
final class ReportWarningsCollectorTest extends TestCase
{
    private BaseCurrencyChecker $baseCurrencyChecker;

    private ReportWarningsCollector $collector;

    protected function setUp(): void
    {
        $this->baseCurrencyChecker = $this->createStub(BaseCurrencyChecker::class);
        $this->baseCurrencyChecker->method('hasMixedCurrencies')->willReturn(false);

        $this->collector = new ReportWarningsCollector($this->baseCurrencyChecker);
    }

    public function testItAddsWarningsForNonAdditiveDimensionsAndLowCostCoverageOnMarginMetrics(): void
    {
        $dataset = $this->buildDataset(nonAdditive: true);
        $request = $this->buildRequest([new DimensionSelection('category_id')]);

        $warnings = $this->collector->collect($dataset, $request, ['gross_margin'], 0.5);

        self::assertCount(2, $warnings);
    }

    public function testItDoesNotWarnAboutCostCoverageWhenItIsAboveTheThreshold(): void
    {
        $dataset = $this->buildDataset(nonAdditive: false);
        $request = $this->buildRequest([]);

        $warnings = $this->collector->collect($dataset, $request, ['gross_margin'], 0.9);

        self::assertSame([], $warnings);
    }

    public function testItDoesNotWarnAboutCostCoverageWhenNoMarginMetricIsRequested(): void
    {
        $dataset = $this->buildDataset(nonAdditive: false);
        $request = $this->buildRequest([]);

        $warnings = $this->collector->collect($dataset, $request, ['net_sales'], 0.1);

        self::assertSame([], $warnings);
    }

    public function testItDoesNotWarnAboutNonAdditiveDimensionsWhenNoneAreSelected(): void
    {
        $dataset = $this->buildDataset(nonAdditive: false);
        $request = $this->buildRequest([new DimensionSelection('store_id')]);

        $warnings = $this->collector->collect($dataset, $request, [], null);

        self::assertSame([], $warnings);
    }

    public function testItAddsAWarningWhenSelectedStoresHaveDifferentBaseCurrencies(): void
    {
        $baseCurrencyChecker = $this->createStub(BaseCurrencyChecker::class);
        $baseCurrencyChecker->method('hasMixedCurrencies')->willReturn(true);
        $collector = new ReportWarningsCollector($baseCurrencyChecker);
        $dataset = $this->buildDataset(nonAdditive: false);
        $request = $this->buildRequest([]);

        $warnings = $collector->collect($dataset, $request, [], null);

        self::assertCount(1, $warnings);
    }

    /**
     * @param DimensionSelection[] $dimensions
     */
    private function buildRequest(array $dimensions): ReportRequestInterface
    {
        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getDimensions')->willReturn($dimensions);
        $request->method('getStoreIds')->willReturn([]);

        return $request;
    }

    private function buildDataset(bool $nonAdditive): Dataset
    {
        return new Dataset(
            code: 'sales_items',
            label: 'Order items',
            aclResource: 'MageOS_AdvancedReport::sales',
            area: 'sales',
            dateless: false,
            baseTable: 'sales_order_item',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: [],
            joins: [],
            metrics: [
                'net_sales' => new Metric(
                    code: 'net_sales',
                    label: 'Net sales',
                    description: null,
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: false,
                    aggregate: AggregateFunction::SUM,
                    fieldCode: 'net_sales',
                ),
            ],
            dimensions: [
                'category_id' => new Dimension(
                    code: 'category_id',
                    label: 'Category',
                    fieldCode: 'category_id',
                    nonAdditive: $nonAdditive,
                ),
                'store_id' => new Dimension(code: 'store_id', label: 'Store', fieldCode: 'store_id'),
            ],
        );
    }
}
