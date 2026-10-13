<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\DateBucketFiller;
use MageOS\AdvancedReport\Model\Query\DateBucketSequence;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateBucketFiller::class)]
final class DateBucketFillerTest extends TestCase
{
    private DateBucketFiller $filler;

    protected function setUp(): void
    {
        $this->filler = new DateBucketFiller(new DateBucketSequence());
    }

    public function testItInsertsMissingBucketsWithZeroAdditiveAggregatesAndNullMinMaxAvg(): void
    {
        $dataset = $this->buildDataset([
            $this->aggregateMetric('net_sales', AggregateFunction::SUM),
            $this->aggregateMetric('orders', AggregateFunction::COUNT),
            $this->aggregateMetric('distinct_customers', AggregateFunction::COUNT_DISTINCT),
            $this->aggregateMetric('min_price', AggregateFunction::MIN),
            $this->aggregateMetric('max_price', AggregateFunction::MAX),
            $this->aggregateMetric('avg_price', AggregateFunction::AVG),
        ]);
        $metricCodes = ['net_sales', 'orders', 'distinct_customers', 'min_price', 'max_price', 'avg_price'];
        $rows = [
            $this->row('2026-03-10', ['net_sales' => 100.0, 'orders' => 4, 'distinct_customers' => 3, 'min_price' => 5.0, 'max_price' => 50.0, 'avg_price' => 25.0]),
            $this->row('2026-03-12', ['net_sales' => 60.0, 'orders' => 2, 'distinct_customers' => 2, 'min_price' => 10.0, 'max_price' => 30.0, 'avg_price' => 20.0]),
        ];

        $filled = $this->filler->fillDateBuckets(
            $dataset,
            $rows,
            'order_date',
            $metricCodes,
            $this->resolvedRange('2026-03-10', '2026-03-12'),
            Granularity::DAY,
        );

        self::assertCount(3, $filled);
        self::assertSame(['order_date' => '2026-03-11'], $filled[1]['dims']);
        self::assertSame(['order_date' => '2026-03-11'], $filled[1]['dims_raw']);
        self::assertSame([
            'net_sales' => 0,
            'orders' => 0,
            'distinct_customers' => 0,
            'min_price' => null,
            'max_price' => null,
            'avg_price' => null,
        ], $filled[1]['metrics']);
    }

    public function testItKeepsExistingRowsUntouchedAndOrdersRowsByBucket(): void
    {
        $dataset = $this->buildDataset([$this->aggregateMetric('orders', AggregateFunction::COUNT)]);
        $existingDay1 = [
            'dims' => ['order_date' => 'Mar 10, 2026'],
            'dims_raw' => ['order_date' => '2026-03-10'],
            'metrics' => ['orders' => 4],
        ];
        $existingDay3 = [
            'dims' => ['order_date' => 'Mar 12, 2026'],
            'dims_raw' => ['order_date' => '2026-03-12'],
            'metrics' => ['orders' => 2],
        ];
        // Rows given out of bucket order on purpose.
        $rows = [$existingDay3, $existingDay1];

        $filled = $this->filler->fillDateBuckets(
            $dataset,
            $rows,
            'order_date',
            ['orders'],
            $this->resolvedRange('2026-03-10', '2026-03-12'),
            Granularity::DAY,
        );

        self::assertSame(
            ['2026-03-10', '2026-03-11', '2026-03-12'],
            array_map(static fn (array $row): string => $row['dims_raw']['order_date'], $filled),
        );
        self::assertSame($existingDay1, $filled[0]);
        self::assertSame($existingDay3, $filled[2]);
    }

    public function testItFillsHourOfDayAndWeekdayDimensionsWithTheirFullDomain(): void
    {
        $dataset = $this->buildDataset([$this->aggregateMetric('orders', AggregateFunction::COUNT)]);
        $hourDomain = array_map(strval(...), range(0, 23));
        $hourRows = [$this->rowForDimension('order_hour', '5', ['orders' => 7])];

        $filledHours = $this->filler->fillFixedDomain($dataset, $hourRows, 'order_hour', ['orders'], $hourDomain);

        self::assertCount(24, $filledHours);
        self::assertSame(['order_hour' => '0'], $filledHours[0]['dims_raw']);
        self::assertSame(['orders' => 0], $filledHours[0]['metrics']);
        self::assertSame(['orders' => 7], $filledHours[5]['metrics']);

        $weekdayDomain = array_map(strval(...), range(0, 6));
        $weekdayRows = [$this->rowForDimension('order_weekday', '2', ['orders' => 9])];

        $filledWeekdays = $this->filler->fillFixedDomain(
            $dataset,
            $weekdayRows,
            'order_weekday',
            ['orders'],
            $weekdayDomain,
        );

        self::assertCount(7, $filledWeekdays);
        self::assertSame(['order_weekday' => '0'], $filledWeekdays[0]['dims_raw']);
        self::assertSame(['orders' => 0], $filledWeekdays[0]['metrics']);
        self::assertSame(['orders' => 9], $filledWeekdays[2]['metrics']);
    }

    /**
     * @param array<string,int|float|null> $metrics
     * @return array{dims: array<string,string>, dims_raw: array<string,string>, metrics: array<string,int|float|null>}
     */
    private function row(string $bucketKey, array $metrics): array
    {
        return $this->rowForDimension('order_date', $bucketKey, $metrics);
    }

    /**
     * @param array<string,int|float|null> $metrics
     * @return array{dims: array<string,string>, dims_raw: array<string,string>, metrics: array<string,int|float|null>}
     */
    private function rowForDimension(string $dimensionCode, string $value, array $metrics): array
    {
        return ['dims' => [$dimensionCode => $value], 'dims_raw' => [$dimensionCode => $value], 'metrics' => $metrics];
    }

    private function resolvedRange(string $from, string $to): ResolvedDateRange
    {
        $fromDate = new DateTimeImmutable($from);
        $toDate = new DateTimeImmutable($to);

        return new ResolvedDateRange(from: $fromDate, to: $toDate, includesToday: false, days: $fromDate->diff($toDate)->days + 1);
    }

    private function aggregateMetric(string $code, AggregateFunction $aggregate): Metric
    {
        return new Metric(
            code: $code,
            label: $code,
            description: null,
            format: 'number',
            hidden: false,
            lowerIsBetter: false,
            aggregate: $aggregate,
            fieldCode: $code,
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
