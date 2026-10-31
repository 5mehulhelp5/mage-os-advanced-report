<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\QuoteRetentionWindow;
use MageOS\AdvancedReport\Model\Query\ReportWarningsCollector;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportWarningsCollector::class)]
final class ReportWarningsCollectorTest extends TestCase
{
    private ReportWarningsCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new ReportWarningsCollector();
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

    public function testItNoLongerWarnsAboutMixedBaseCurrencies(): void
    {
        $dataset = $this->buildDataset(nonAdditive: false);

        $warnings = $this->collector->collect($dataset, $this->buildRequest([]), [], null);

        self::assertSame([], $warnings);
    }

    public function testItWarnsWhenACartReportRangeStartsBeforeTheQuoteLifetimeWindow(): void
    {
        $warnings = $this->collect($this->window(30), $this->range('2026-02-01', '2026-03-10'), null);

        self::assertSame(
            ['Carts not updated for more than 30 days are deleted by Magento '
                . '(Sales > Checkout > Shopping Cart > Quote Lifetime); results before 2026-02-13 are incomplete.'],
            $warnings,
        );
    }

    public function testItWarnsWhenTheComparisonRangeStartsBeforeTheQuoteLifetimeWindow(): void
    {
        $warnings = $this->collect(
            $this->window(30),
            $this->range('2026-03-01', '2026-03-10'),
            $this->range('2026-02-01', '2026-02-10'),
        );

        self::assertCount(1, $warnings);
    }

    public function testItDoesNotWarnForCartRangesInsideTheQuoteLifetimeWindow(): void
    {
        $warnings = $this->collect(
            $this->window(30),
            $this->range('2026-02-13', '2026-03-10'),
            $this->range('2026-02-14', '2026-02-20'),
        );

        self::assertSame([], $warnings);
    }

    public function testItDoesNotWarnForDatasetsOutsideTheCartsArea(): void
    {
        self::assertSame([], $this->collect(null, $this->range('2020-01-01', '2020-02-01'), null));
    }

    private function window(int $days): QuoteRetentionWindow
    {
        return new QuoteRetentionWindow($days, (new DateTimeImmutable('2026-03-15'))->modify("-$days days"));
    }

    private function range(string $from, string $to): ResolvedDateRange
    {
        return new ResolvedDateRange(new DateTimeImmutable($from), new DateTimeImmutable($to), false, 10);
    }

    /**
     * @return string[]
     */
    private function collect(?QuoteRetentionWindow $window, ResolvedDateRange $range, ?ResolvedDateRange $comparison): array
    {
        return $this->collector->collect(
            $this->buildDataset(nonAdditive: false),
            $this->buildRequest([]),
            [],
            null,
            $window,
            $range,
            $comparison,
        );
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
