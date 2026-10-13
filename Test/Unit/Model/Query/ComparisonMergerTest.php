<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateInterval;
use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Query\ComparisonMerger;
use MageOS\AdvancedReport\Model\Query\ComparisonPeriod;
use MageOS\AdvancedReport\Model\Query\DateBucketAlignment;
use MageOS\AdvancedReport\Model\Query\DateBucketSequence;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComparisonMerger::class)]
final class ComparisonMergerTest extends TestCase
{
    private ComparisonMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new ComparisonMerger(new DateBucketSequence());
    }

    public function testItMatchesNonDateRowsByDimensionKey(): void
    {
        $currentRows = [
            $this->row(['store_id' => 1], ['net_sales' => 100.0]),
            $this->row(['store_id' => 2], ['net_sales' => 50.0]),
        ];
        $comparisonRows = [
            $this->row(['store_id' => 2], ['net_sales' => 40.0]),
            $this->row(['store_id' => 1], ['net_sales' => 80.0]),
        ];

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales']);

        self::assertSame(80.0, $merged[0]['comparison']['net_sales']['value']);
        self::assertSame(40.0, $merged[1]['comparison']['net_sales']['value']);
    }

    public function testItAlignsDailyBucketsByThePreviousPeriodOffset(): void
    {
        $currentRows = [
            $this->row(['order_date' => '2026-03-10'], ['net_sales' => 100.0]),
            $this->row(['order_date' => '2026-03-11'], ['net_sales' => 110.0]),
        ];
        $comparisonRows = [
            $this->row(['order_date' => '2026-03-03'], ['net_sales' => 80.0]),
            $this->row(['order_date' => '2026-03-04'], ['net_sales' => 90.0]),
        ];
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_date',
            granularity: Granularity::DAY,
            currentRange: $this->resolvedRange('2026-03-10', '2026-03-11'),
            comparisonPeriod: new ComparisonPeriod(
                $this->resolvedRange('2026-03-03', '2026-03-04'),
                new DateInterval('P7D'),
            ),
        );

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales'], $alignment);

        self::assertSame(80.0, $merged[0]['comparison']['net_sales']['value']);
        self::assertSame(90.0, $merged[1]['comparison']['net_sales']['value']);
    }

    public function testItAlignsMonthlyBucketsByThePreviousYearOffset(): void
    {
        $currentRows = [
            $this->row(['order_month' => '2026-01'], ['net_sales' => 100.0]),
            $this->row(['order_month' => '2026-02'], ['net_sales' => 110.0]),
        ];
        $comparisonRows = [
            $this->row(['order_month' => '2025-01'], ['net_sales' => 70.0]),
            $this->row(['order_month' => '2025-02'], ['net_sales' => 75.0]),
        ];
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_month',
            granularity: Granularity::MONTH,
            currentRange: $this->resolvedRange('2026-01-01', '2026-02-28'),
            comparisonPeriod: new ComparisonPeriod(
                $this->resolvedRange('2025-01-01', '2025-02-28'),
                new DateInterval('P1Y'),
            ),
        );

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales'], $alignment);

        self::assertSame(70.0, $merged[0]['comparison']['net_sales']['value']);
        self::assertSame(75.0, $merged[1]['comparison']['net_sales']['value']);
    }

    public function testItAlignsWeeklyAndMonthlyBucketsByOrdinalPositionForPreviousPeriodComparisonsEvenWhenSomeBucketsAreEmpty(): void
    {
        $bucketSequence = new DateBucketSequence();
        $firstDayOfWeek = 1;

        // "last_30_days"-style ranges: the previous period is exactly 30 days back, which is
        // not a multiple of 7, so a fixed day offset would not land on week starts.
        $currentRange = $this->resolvedRange('2026-02-15', '2026-03-16');
        $comparisonRange = $this->resolvedRange('2026-01-16', '2026-02-14');
        $offset = $comparisonRange->from->diff($currentRange->from);

        $currentWeeks = $bucketSequence->generate($currentRange, Granularity::WEEK, $firstDayOfWeek);
        $comparisonWeeks = $bucketSequence->generate($comparisonRange, Granularity::WEEK, $firstDayOfWeek);
        self::assertGreaterThanOrEqual(3, \count($currentWeeks));
        self::assertGreaterThanOrEqual(3, \count($comparisonWeeks));

        // Ordinal 1 has no current-period row: an empty bucket must not shift the mapping.
        $currentRows = [
            $this->row(['order_week' => $currentWeeks[0]], ['net_sales' => 100.0]),
            $this->row(['order_week' => $currentWeeks[2]], ['net_sales' => 120.0]),
        ];
        $comparisonRows = [
            $this->row(['order_week' => $comparisonWeeks[0]], ['net_sales' => 70.0]),
            $this->row(['order_week' => $comparisonWeeks[2]], ['net_sales' => 90.0]),
        ];
        $weekAlignment = new DateBucketAlignment(
            dimensionCode: 'order_week',
            granularity: Granularity::WEEK,
            currentRange: $currentRange,
            comparisonPeriod: new ComparisonPeriod($comparisonRange, $offset),
            firstDayOfWeek: $firstDayOfWeek,
        );

        $mergedWeeks = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales'], $weekAlignment);

        self::assertSame(70.0, $mergedWeeks[0]['comparison']['net_sales']['value']);
        self::assertSame(90.0, $mergedWeeks[1]['comparison']['net_sales']['value']);

        $currentMonths = $bucketSequence->generate($currentRange, Granularity::MONTH);
        $comparisonMonths = $bucketSequence->generate($comparisonRange, Granularity::MONTH);
        self::assertGreaterThanOrEqual(2, \count($currentMonths));
        self::assertGreaterThanOrEqual(2, \count($comparisonMonths));

        $monthCurrentRows = [$this->row(['order_month' => $currentMonths[1]], ['net_sales' => 130.0])];
        $monthComparisonRows = [$this->row(['order_month' => $comparisonMonths[1]], ['net_sales' => 95.0])];
        $monthAlignment = new DateBucketAlignment(
            dimensionCode: 'order_month',
            granularity: Granularity::MONTH,
            currentRange: $currentRange,
            comparisonPeriod: new ComparisonPeriod($comparisonRange, $offset),
        );

        $mergedMonths = $this->merger->mergeRows($monthCurrentRows, $monthComparisonRows, ['net_sales'], $monthAlignment);

        self::assertSame(95.0, $mergedMonths[0]['comparison']['net_sales']['value']);
    }

    public function testItAlignsHourlyBucketsByThePreviousPeriodOffset(): void
    {
        $currentRows = [
            $this->row(['order_hour' => '2026-03-10 09:00'], ['net_sales' => 100.0]),
        ];
        $comparisonRows = [
            $this->row(['order_hour' => '2026-03-03 09:00'], ['net_sales' => 60.0]),
        ];
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_hour',
            granularity: Granularity::HOUR,
            currentRange: $this->resolvedRange('2026-03-10', '2026-03-10'),
            comparisonPeriod: new ComparisonPeriod(
                $this->resolvedRange('2026-03-03', '2026-03-03'),
                new DateInterval('P7D'),
            ),
        );

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales'], $alignment);

        self::assertSame(60.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItAlignsYearlyBucketsByOrdinalPosition(): void
    {
        $currentRows = [
            $this->row(['order_year' => '2026'], ['net_sales' => 200.0]),
        ];
        $comparisonRows = [
            $this->row(['order_year' => '2020'], ['net_sales' => 150.0]),
        ];
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_year',
            granularity: Granularity::YEAR,
            currentRange: $this->resolvedRange('2026-01-01', '2026-12-31'),
            comparisonPeriod: new ComparisonPeriod(
                $this->resolvedRange('2020-01-01', '2020-12-31'),
                new DateInterval('P6Y'),
            ),
        );

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales'], $alignment);

        self::assertSame(150.0, $merged[0]['comparison']['net_sales']['value']);
    }

    private function resolvedRange(string $from, string $to): ResolvedDateRange
    {
        $fromDate = new DateTimeImmutable($from);
        $toDate = new DateTimeImmutable($to);

        return new ResolvedDateRange(
            from: $fromDate,
            to: $toDate,
            includesToday: false,
            days: $fromDate->diff($toDate)->days + 1,
        );
    }

    public function testItComputesAbsoluteAndPercentageDeltas(): void
    {
        $currentRows = [$this->row(['store_id' => 1], ['net_sales' => 120.0])];
        $comparisonRows = [$this->row(['store_id' => 1], ['net_sales' => 100.0])];

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales']);

        self::assertSame(20.0, $merged[0]['comparison']['net_sales']['delta']);
        self::assertSame(0.2, $merged[0]['comparison']['net_sales']['delta_pct']);
    }

    public function testItMergesTotalsComparison(): void
    {
        $totals = ['metrics' => ['net_sales' => 300.0]];
        $comparisonTotals = ['metrics' => ['net_sales' => 250.0]];

        $merged = $this->merger->mergeTotals($totals, $comparisonTotals, ['net_sales']);

        self::assertSame(['net_sales' => 300.0], $merged['metrics']);
        self::assertSame(250.0, $merged['comparison']['net_sales']['value']);
        self::assertSame(50.0, $merged['comparison']['net_sales']['delta']);
        self::assertSame(0.2, $merged['comparison']['net_sales']['delta_pct']);
    }

    public function testItReturnsNullPercentageDeltaWhenThePreviousValueIsZeroOrMissing(): void
    {
        $currentRows = [
            $this->row(['store_id' => 1], ['net_sales' => 50.0]),
            $this->row(['store_id' => 2], ['net_sales' => 30.0]),
        ];
        $comparisonRows = [
            $this->row(['store_id' => 1], ['net_sales' => 0.0]),
        ];

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales']);

        self::assertSame(50.0, $merged[0]['comparison']['net_sales']['delta']);
        self::assertNull($merged[0]['comparison']['net_sales']['delta_pct']);
        self::assertNull($merged[1]['comparison']['net_sales']['value']);
        self::assertNull($merged[1]['comparison']['net_sales']['delta']);
        self::assertNull($merged[1]['comparison']['net_sales']['delta_pct']);
    }

    public function testItMatchesRowsByRawDimensionValuesRatherThanLabels(): void
    {
        // Two different stores that happen to share the same resolved label; only the raw
        // store_id must be used to match rows, never the (ambiguous) label.
        $currentRows = [
            $this->rowWithLabel('Main Store', ['store_id' => 1], ['net_sales' => 100.0]),
            $this->rowWithLabel('Main Store', ['store_id' => 2], ['net_sales' => 50.0]),
        ];
        $comparisonRows = [
            $this->rowWithLabel('Main Store (old name)', ['store_id' => 1], ['net_sales' => 80.0]),
            $this->rowWithLabel('Main Store', ['store_id' => 2], ['net_sales' => 40.0]),
        ];

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales']);

        self::assertSame(80.0, $merged[0]['comparison']['net_sales']['value']);
        self::assertSame(40.0, $merged[1]['comparison']['net_sales']['value']);
    }

    /**
     * @param array<string,mixed> $dimsRaw
     * @param array<string,int|float|null> $metrics
     * @return array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}
     */
    private function rowWithLabel(string $label, array $dimsRaw, array $metrics): array
    {
        return ['dims' => ['store' => $label], 'dims_raw' => $dimsRaw, 'metrics' => $metrics];
    }

    /**
     * @param array<string,mixed> $dimsRaw
     * @param array<string,int|float|null> $metrics
     * @return array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}
     */
    private function row(array $dimsRaw, array $metrics): array
    {
        return ['dims' => $dimsRaw, 'dims_raw' => $dimsRaw, 'metrics' => $metrics];
    }
}
