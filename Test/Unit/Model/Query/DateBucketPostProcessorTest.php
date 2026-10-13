<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Label\HourOfDayLabelResolver;
use MageOS\AdvancedReport\Model\Label\LabelResolverPool;
use MageOS\AdvancedReport\Model\Label\WeekdayLabelResolver;
use MageOS\AdvancedReport\Model\Query\DateBucketFiller;
use MageOS\AdvancedReport\Model\Query\DateBucketPostProcessor;
use MageOS\AdvancedReport\Model\Query\DateBucketSequence;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateBucketPostProcessor::class)]
final class DateBucketPostProcessorTest extends TestCase
{
    private DateBucketPostProcessor $postProcessor;

    protected function setUp(): void
    {
        $this->postProcessor = new DateBucketPostProcessor(
            new DateBucketFiller(new DateBucketSequence()),
            new LabelResolverPool([
                HourOfDayLabelResolver::class => new HourOfDayLabelResolver(),
                WeekdayLabelResolver::class => new WeekdayLabelResolver(),
            ]),
            [
                'order_hour' => array_map(strval(...), range(0, 23)),
                'order_weekday' => array_map(strval(...), range(0, 6)),
            ],
        );
    }

    public function testItDoesNotSupportRequestsWithMoreThanOneDimension(): void
    {
        $request = $this->buildRequest([
            new DimensionSelection('order_date', Granularity::DAY),
            new DimensionSelection('store_id'),
        ]);

        self::assertFalse($this->postProcessor->supports($request));
    }

    public function testItDoesNotSupportASingleDimensionWithoutGranularityOrFixedDomain(): void
    {
        $request = $this->buildRequest([new DimensionSelection('store_id')]);

        self::assertFalse($this->postProcessor->supports($request));
    }

    public function testItFillsAGranularityBasedDateBucketDimension(): void
    {
        $request = $this->buildRequest([new DimensionSelection('order_date', Granularity::DAY)]);
        $dataset = $this->buildDataset();
        $rows = [$this->row('order_date', '2026-03-10', 4)];

        self::assertTrue($this->postProcessor->supports($request));

        $processed = $this->postProcessor->process(
            $dataset,
            $request,
            $rows,
            ['orders'],
            $this->resolvedRange('2026-03-10', '2026-03-12'),
            0,
        );

        self::assertSame(3, $processed['totalRowCount']);
        self::assertCount(3, $processed['rows']);
        self::assertSame(
            ['2026-03-10', '2026-03-11', '2026-03-12'],
            array_map(static fn (array $row): string => $row['dims_raw']['order_date'], $processed['rows']),
        );
    }

    public function testItFillsAFixedDomainDimension(): void
    {
        $request = $this->buildRequest([new DimensionSelection('order_hour')]);
        $dataset = $this->buildDataset();
        $rows = [$this->row('order_hour', '5', 7)];

        self::assertTrue($this->postProcessor->supports($request));

        $processed = $this->postProcessor->process($dataset, $request, $rows, ['orders'], null, 0);

        self::assertSame(24, $processed['totalRowCount']);
        self::assertCount(24, $processed['rows']);
        self::assertSame(['orders' => 7], $processed['rows'][5]['metrics']);
    }

    public function testItLabelsTheFilledHourOfDayBuckets(): void
    {
        $request = $this->buildRequest([new DimensionSelection('order_hour')]);
        $rows = [$this->row('order_hour', '5', 7, '05:00')];

        $processed = $this->postProcessor->process($this->buildDataset(), $request, $rows, ['orders'], null, 0);

        self::assertSame(
            array_map(static fn (int $hour): string => \sprintf('%02d:00', $hour), range(0, 23)),
            array_map(static fn (array $row): string => $row['dims']['order_hour'], $processed['rows']),
        );
    }

    public function testItLabelsTheFilledWeekdayBuckets(): void
    {
        $request = $this->buildRequest([new DimensionSelection('order_weekday')]);
        $rows = [$this->row('order_weekday', '1', 3, 'Monday')];

        $processed = $this->postProcessor->process($this->buildDataset(), $request, $rows, ['orders'], null, 0);

        self::assertSame(
            ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            array_map(static fn (array $row): string => $row['dims']['order_weekday'], $processed['rows']),
        );
        self::assertSame(['0', '1', '2', '3', '4', '5', '6'], array_map(
            static fn (array $row): string => $row['dims_raw']['order_weekday'],
            $processed['rows'],
        ));
    }

    public function testItKeepsRawKeysForDimensionsWithoutLabelResolver(): void
    {
        $request = $this->buildRequest([new DimensionSelection('order_date', Granularity::DAY)]);

        $processed = $this->postProcessor->process(
            $this->buildDataset(),
            $request,
            [],
            ['orders'],
            $this->resolvedRange('2026-03-10', '2026-03-11'),
            0,
        );

        self::assertSame(
            ['2026-03-10', '2026-03-11'],
            array_map(static fn (array $row): string => $row['dims']['order_date'], $processed['rows']),
        );
    }

    /**
     * @param DimensionSelection[] $dimensions
     */
    private function buildRequest(array $dimensions): ReportRequestInterface
    {
        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getDimensions')->willReturn($dimensions);

        return $request;
    }

    /**
     * @return array{dims: array<string,string>, dims_raw: array<string,string>, metrics: array<string,int>}
     */
    private function row(string $dimensionCode, string $value, int $orders, ?string $label = null): array
    {
        return [
            'dims' => [$dimensionCode => $label ?? $value],
            'dims_raw' => [$dimensionCode => $value],
            'metrics' => ['orders' => $orders],
        ];
    }

    private function resolvedRange(string $from, string $to): ResolvedDateRange
    {
        $fromDate = new DateTimeImmutable($from);
        $toDate = new DateTimeImmutable($to);

        return new ResolvedDateRange(from: $fromDate, to: $toDate, includesToday: false, days: $fromDate->diff($toDate)->days + 1);
    }

    private function buildDataset(): Dataset
    {
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
            metrics: [
                'orders' => new Metric(
                    code: 'orders',
                    label: 'Orders',
                    description: null,
                    format: 'integer',
                    hidden: false,
                    lowerIsBetter: false,
                    aggregate: AggregateFunction::COUNT,
                    fieldCode: 'orders',
                ),
            ],
            dimensions: [
                'order_date' => new Dimension('order_date', 'Order date', 'created_at', [Granularity::DAY]),
                'order_hour' => new Dimension(
                    'order_hour',
                    'Order hour',
                    'created_at',
                    labelResolver: HourOfDayLabelResolver::class,
                ),
                'order_weekday' => new Dimension(
                    'order_weekday',
                    'Order weekday',
                    'created_at',
                    labelResolver: WeekdayLabelResolver::class,
                ),
            ],
        );
    }
}
