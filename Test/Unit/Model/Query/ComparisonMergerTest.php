<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Query\CompareMode;
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
                CompareMode::PREVIOUS_PERIOD,
                7,
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
                CompareMode::PREVIOUS_YEAR,
                365,
            ),
        );

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales'], $alignment);

        self::assertSame(70.0, $merged[0]['comparison']['net_sales']['value']);
        self::assertSame(75.0, $merged[1]['comparison']['net_sales']['value']);
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
                CompareMode::PREVIOUS_PERIOD,
                7,
            ),
        );

        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales'], $alignment);

        self::assertSame(60.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItPairsTheOctoberMonthBucketWithTheSeptemberBucketForLastMonthAgainstThePreviousPeriod(): void
    {
        $merged = $this->mergeBuckets(
            Granularity::MONTH,
            'order_month',
            CompareMode::PREVIOUS_PERIOD,
            ['2024-10-01', '2024-10-31'],
            ['2024-08-31', '2024-09-30'],
            30,
            '2024-10',
            '2024-09',
        );

        self::assertSame(7.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItPairsMonthBucketsWithTheSameMonthOneYearEarlierAgainstThePreviousYear(): void
    {
        $merged = $this->mergeBuckets(
            Granularity::MONTH,
            'order_month',
            CompareMode::PREVIOUS_YEAR,
            ['2024-02-15', '2024-04-10'],
            ['2023-01-10', '2023-03-20'],
            365,
            '2024-03',
            '2023-03',
        );

        self::assertSame(7.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItPairsWeekBucketsWithTheWeekContainingTheSameDateOneYearEarlierAlignedToTheConfiguredFirstDayOfWeek(): void
    {
        // 2024-03-04 is a Monday; 2023-03-04 is a Saturday, so with Monday as first day of week
        // its week starts on 2023-02-27.
        $merged = $this->mergeBuckets(
            Granularity::WEEK,
            'order_week',
            CompareMode::PREVIOUS_YEAR,
            ['2024-03-04', '2024-03-10'],
            ['2023-03-04', '2023-03-10'],
            366,
            '2024-03-04',
            '2023-02-27',
            1,
        );

        self::assertSame(7.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItPairsWeekBucketsWithTheBucketNWeeksEarlierAgainstThePreviousPeriod(): void
    {
        // 2024-03-04..2024-03-24 spans 3 Monday-based weeks: 03-18 pairs with 03-18 - 3 weeks = 02-26.
        $merged = $this->mergeBuckets(
            Granularity::WEEK,
            'order_week',
            CompareMode::PREVIOUS_PERIOD,
            ['2024-03-04', '2024-03-24'],
            ['2024-02-12', '2024-03-03'],
            21,
            '2024-03-18',
            '2024-02-26',
            1,
        );

        self::assertSame(7.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItPairsYearBucketsWithThePrecedingYear(): void
    {
        $merged = $this->mergeBuckets(
            Granularity::YEAR,
            'order_year',
            CompareMode::PREVIOUS_PERIOD,
            ['2026-01-01', '2026-12-31'],
            ['2025-01-01', '2025-12-31'],
            365,
            '2026',
            '2025',
        );

        self::assertSame(7.0, $merged[0]['comparison']['net_sales']['value']);
    }

    /**
     * @param array{string,string} $current
     * @param array{string,string} $comparison
     * @return array<int,array<string,mixed>>
     */
    private function mergeBuckets(
        Granularity $granularity,
        string $dimension,
        CompareMode $mode,
        array $current,
        array $comparison,
        int $offsetDays,
        string $currentKey,
        string $comparisonKey,
        int $firstDayOfWeek = 0,
    ): array {
        $alignment = new DateBucketAlignment(
            dimensionCode: $dimension,
            granularity: $granularity,
            currentRange: $this->resolvedRange(...$current),
            comparisonPeriod: new ComparisonPeriod($this->resolvedRange(...$comparison), $mode, $offsetDays),
            firstDayOfWeek: $firstDayOfWeek,
        );

        return $this->merger->mergeRows(
            [$this->row([$dimension => $currentKey], ['net_sales' => 1.0])],
            [$this->row([$dimension => $comparisonKey], ['net_sales' => 7.0])],
            ['net_sales'],
            $alignment,
        );
    }

    public function testItPairs20240531With20240302For20240401To20240629AgainstThePreviousPeriod(): void
    {
        $current = $this->resolvedRange('2024-04-01', '2024-06-29');
        $comparison = $this->resolvedRange('2024-01-02', '2024-03-31');
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_date',
            granularity: Granularity::DAY,
            currentRange: $current,
            comparisonPeriod: new ComparisonPeriod($comparison, CompareMode::PREVIOUS_PERIOD, 90),
        );
        $merged = $this->merger->mergeRows(
            [$this->row(['order_date' => '2024-05-31'], ['net_sales' => 5.0])],
            [$this->row(['order_date' => '2024-03-02'], ['net_sales' => 3.0])],
            ['net_sales'],
            $alignment,
        );

        self::assertSame(3.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItPairsEveryDayBucketOfYearToDateWithTheDayExactlyNDaysEarlierAgainstThePreviousPeriod(): void
    {
        $current = $this->resolvedRange('2025-01-01', '2025-10-30');
        $comparison = $this->resolvedRange('2024-03-06', '2024-12-31');
        $days = $current->days;
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_date',
            granularity: Granularity::DAY,
            currentRange: $current,
            comparisonPeriod: new ComparisonPeriod($comparison, CompareMode::PREVIOUS_PERIOD, $days),
        );

        $currentRows = [];
        $comparisonRows = [];
        for ($date = $current->from; $date <= $current->to; $date = $date->modify('+1 day')) {
            $currentRows[] = $this->row(['order_date' => $date->format('Y-m-d')], ['net_sales' => 1.0]);
            $comparisonRows[] = $this->row(
                ['order_date' => $date->modify(\sprintf('-%d days', $days))->format('Y-m-d')],
                ['net_sales' => (float) $date->format('z')],
            );
        }
        $merged = $this->merger->mergeRows($currentRows, $comparisonRows, ['net_sales'], $alignment);

        foreach ($merged as $index => $row) {
            self::assertSame((float) $index, $row['comparison']['net_sales']['value']);
        }
    }

    public function testItPairsDayBucketsOfARangeStartingOn29FebruaryWithTheSameCalendarDayOneYearEarlier(): void
    {
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_date',
            granularity: Granularity::DAY,
            currentRange: $this->resolvedRange('2024-02-29', '2024-03-02'),
            comparisonPeriod: new ComparisonPeriod(
                $this->resolvedRange('2023-02-28', '2023-03-02'),
                CompareMode::PREVIOUS_YEAR,
                366,
            ),
        );
        $merged = $this->merger->mergeRows(
            [
                $this->row(['order_date' => '2024-02-29'], ['net_sales' => 1.0]),
                $this->row(['order_date' => '2024-03-01'], ['net_sales' => 1.0]),
            ],
            [
                $this->row(['order_date' => '2023-02-28'], ['net_sales' => 28.0]),
                $this->row(['order_date' => '2023-03-01'], ['net_sales' => 31.0]),
            ],
            ['net_sales'],
            $alignment,
        );

        self::assertSame(28.0, $merged[0]['comparison']['net_sales']['value']);
        self::assertSame(31.0, $merged[1]['comparison']['net_sales']['value']);
    }

    public function testItPairs20240301With20230301For20240201To20240331AgainstThePreviousYear(): void
    {
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_date',
            granularity: Granularity::DAY,
            currentRange: $this->resolvedRange('2024-02-01', '2024-03-31'),
            comparisonPeriod: new ComparisonPeriod(
                $this->resolvedRange('2023-02-01', '2023-03-31'),
                CompareMode::PREVIOUS_YEAR,
                365,
            ),
        );
        $merged = $this->merger->mergeRows(
            [$this->row(['order_date' => '2024-03-01'], ['net_sales' => 1.0])],
            [$this->row(['order_date' => '2023-03-01'], ['net_sales' => 7.0])],
            ['net_sales'],
            $alignment,
        );

        self::assertSame(7.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItPairsHourBucketsAcrossADstChangeWithTheSameWallClockHourNDaysEarlier(): void
    {
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_hour',
            granularity: Granularity::HOUR,
            currentRange: $this->resolvedRange('2026-03-29', '2026-03-29'),
            comparisonPeriod: new ComparisonPeriod(
                $this->resolvedRange('2026-03-28', '2026-03-28'),
                CompareMode::PREVIOUS_PERIOD,
                1,
            ),
        );
        $merged = $this->merger->mergeRows(
            [
                $this->row(['order_hour' => '2026-03-29 03:00'], ['net_sales' => 1.0]),
                $this->row(['order_hour' => '2026-03-29 00:00'], ['net_sales' => 1.0]),
            ],
            [
                $this->row(['order_hour' => '2026-03-28 03:00'], ['net_sales' => 3.0]),
                $this->row(['order_hour' => '2026-03-28 00:00'], ['net_sales' => 4.0]),
            ],
            ['net_sales'],
            $alignment,
        );

        self::assertSame(3.0, $merged[0]['comparison']['net_sales']['value']);
        self::assertSame(4.0, $merged[1]['comparison']['net_sales']['value']);
    }

    public function testItPairsHourBucketsWithTheSameWallClockHourOneYearEarlierAgainstThePreviousYear(): void
    {
        $alignment = new DateBucketAlignment(
            dimensionCode: 'order_hour',
            granularity: Granularity::HOUR,
            currentRange: $this->resolvedRange('2024-02-29', '2024-02-29'),
            comparisonPeriod: new ComparisonPeriod(
                $this->resolvedRange('2023-02-28', '2023-02-28'),
                CompareMode::PREVIOUS_YEAR,
                366,
            ),
        );
        $merged = $this->merger->mergeRows(
            [$this->row(['order_hour' => '2024-02-29 14:00'], ['net_sales' => 1.0])],
            [$this->row(['order_hour' => '2023-02-28 14:00'], ['net_sales' => 14.0])],
            ['net_sales'],
            $alignment,
        );

        self::assertSame(14.0, $merged[0]['comparison']['net_sales']['value']);
    }

    public function testItComputesDeltaPercentAgainstTheAbsolutePreviousValue(): void
    {
        $merged = $this->merger->mergeRows(
            [$this->row(['store_id' => 1], ['net_sales' => -50.0])],
            [$this->row(['store_id' => 1], ['net_sales' => -100.0])],
            ['net_sales'],
        );

        self::assertSame(50.0, $merged[0]['comparison']['net_sales']['delta']);
        self::assertSame(0.5, $merged[0]['comparison']['net_sales']['delta_pct']);
    }

    public function testItReturnsNullDeltaPercentWhenThePreviousValueIsZero(): void
    {
        $merged = $this->merger->mergeRows(
            [$this->row(['store_id' => 1], ['net_sales' => 10.0])],
            [$this->row(['store_id' => 1], ['net_sales' => 0.0])],
            ['net_sales'],
        );

        self::assertNull($merged[0]['comparison']['net_sales']['delta_pct']);
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
