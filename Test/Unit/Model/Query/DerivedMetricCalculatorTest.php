<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DerivedMetricCalculator::class)]
final class DerivedMetricCalculatorTest extends TestCase
{
    private DerivedMetricCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DerivedMetricCalculator();
    }

    public function testItListsAggregatesNeededTransitivelyByRequestedDerivedMetrics(): void
    {
        $dataset = $this->buildDataset([
            $this->aggregateMetric('net_sales'),
            $this->aggregateMetric('cost'),
            $this->aggregateMetric('orders_count'),
            $this->derivedMetric('gross_margin', 'net_sales - cost'),
        ]);

        $result = $this->calculator->getRequiredAggregates($dataset, ['gross_margin', 'orders_count']);

        self::assertSame(['net_sales', 'cost', 'orders_count'], $result);
    }

    public function testItComputesDerivedMetricsOnEachRow(): void
    {
        $dataset = $this->buildDataset([
            $this->aggregateMetric('net_sales'),
            $this->aggregateMetric('orders_count'),
            $this->derivedMetric('avg_order_value', 'net_sales / orders_count'),
        ]);

        $result = $this->calculator->apply(
            $dataset,
            ['avg_order_value'],
            [$this->row(['net_sales' => 100.0, 'orders_count' => 4.0])],
            []
        );

        self::assertSame(['avg_order_value' => 25.0], $result['rows'][0]['metrics']);
    }

    public function testItComputesNestedDerivedMetricsInDependencyOrder(): void
    {
        $dataset = $this->buildDataset([
            $this->aggregateMetric('net_sales'),
            $this->aggregateMetric('cost'),
            $this->derivedMetric('gross_margin', 'net_sales - cost'),
            $this->derivedMetric('margin_pct', 'gross_margin / net_sales'),
        ]);

        $result = $this->calculator->apply(
            $dataset,
            ['margin_pct'],
            [$this->row(['net_sales' => 200.0, 'cost' => 50.0])],
            []
        );

        self::assertSame(['margin_pct' => 0.75], $result['rows'][0]['metrics']);
    }

    public function testItRecomputesDerivedTotalsFromAggregateTotalsRatherThanSummingRows(): void
    {
        $dataset = $this->buildDataset([
            $this->aggregateMetric('net_sales'),
            $this->aggregateMetric('orders_count'),
            $this->derivedMetric('avg_order_value', 'net_sales / orders_count'),
        ]);

        $result = $this->calculator->apply(
            $dataset,
            ['avg_order_value'],
            [
                $this->row(['net_sales' => 100.0, 'orders_count' => 2.0]),
                $this->row(['net_sales' => 100.0, 'orders_count' => 8.0]),
            ],
            ['net_sales' => 200.0, 'orders_count' => 10.0]
        );

        self::assertSame(['avg_order_value' => 20.0], $result['totals']);
    }

    public function testItYieldsNullForDerivedMetricsOnDivisionByZero(): void
    {
        $dataset = $this->buildDataset([
            $this->aggregateMetric('net_sales'),
            $this->aggregateMetric('orders_count'),
            $this->derivedMetric('avg_order_value', 'net_sales / orders_count'),
        ]);

        $result = $this->calculator->apply(
            $dataset,
            ['avg_order_value'],
            [$this->row(['net_sales' => 100.0, 'orders_count' => 0.0])],
            []
        );

        self::assertNull($result['rows'][0]['metrics']['avg_order_value']);
    }

    public function testItStripsHelperAggregatesThatWereNotRequested(): void
    {
        $dataset = $this->buildDataset([
            $this->aggregateMetric('net_sales'),
            $this->aggregateMetric('cost'),
            $this->derivedMetric('gross_margin', 'net_sales - cost'),
        ]);

        $result = $this->calculator->apply(
            $dataset,
            ['gross_margin'],
            [$this->row(['net_sales' => 100.0, 'cost' => 40.0])],
            ['net_sales' => 100.0, 'cost' => 40.0]
        );

        self::assertSame(['gross_margin' => 60.0], $result['rows'][0]['metrics']);
        self::assertSame(['gross_margin' => 60.0], $result['totals']);
    }

    /**
     * @param array<string,int|float|null> $metrics
     * @return array{dims: array<string,string>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}
     */
    private function row(array $metrics): array
    {
        return ['dims' => [], 'dims_raw' => [], 'metrics' => $metrics];
    }

    private function aggregateMetric(string $code): Metric
    {
        return new Metric(
            code: $code,
            label: $code,
            description: null,
            format: 'number',
            hidden: false,
            lowerIsBetter: false,
            aggregate: AggregateFunction::SUM,
            fieldCode: $code,
        );
    }

    private function derivedMetric(string $code, string $formula): Metric
    {
        return new Metric(
            code: $code,
            label: $code,
            description: null,
            format: 'number',
            hidden: false,
            lowerIsBetter: false,
            formula: $formula,
        );
    }

    /**
     * @param Metric[] $metrics
     */
    private function buildDataset(array $metrics): Dataset
    {
        $indexed = [];
        foreach ($metrics as $metric) {
            $indexed[$metric->code] = $metric;
        }

        return new Dataset(
            code: 'sales_orders',
            label: 'Sales Orders',
            aclResource: 'MageOS_AdvancedReport::sales',
            area: 'sales',
            dateless: false,
            baseTable: 'sales_order',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: [],
            joins: [],
            metrics: $indexed,
            dimensions: [],
        );
    }
}
