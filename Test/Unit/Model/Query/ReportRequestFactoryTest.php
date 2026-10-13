<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportRequestFactory::class)]
final class ReportRequestFactoryTest extends TestCase
{
    private ReportRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ReportRequestFactory();
    }

    public function testItBuildsARequestFromACompleteArrayPayload(): void
    {
        $request = $this->factory->fromArray($this->completePayload());

        self::assertSame('sales_orders', $request->getDataset());
        self::assertSame('sales_overview', $request->getPreset());
        self::assertSame(['orders', 'net_sales'], $request->getMetrics());

        $dimensions = $request->getDimensions();
        self::assertCount(2, $dimensions);
        self::assertSame('order_date', $dimensions[0]->code);
        self::assertSame(Granularity::DAY, $dimensions[0]->granularity);
        self::assertSame('channel', $dimensions[1]->code);
        self::assertNull($dimensions[1]->granularity);

        $filters = $request->getFilters();
        self::assertCount(2, $filters);
        self::assertSame('status', $filters[0]->field);
        self::assertSame(FilterOperator::EQ, $filters[0]->operator);
        self::assertSame('complete', $filters[0]->value);
        self::assertSame('store_id', $filters[1]->field);
        self::assertSame(FilterOperator::IN, $filters[1]->operator);
        self::assertSame([1, 2], $filters[1]->value);

        self::assertSame(DateRangeType::LAST_30_DAYS, $request->getDateRange()->type);
        self::assertNull($request->getDateRange()->from);
        self::assertNull($request->getDateRange()->to);

        self::assertSame(CompareMode::PREVIOUS_PERIOD, $request->getCompareTo());

        $sort = $request->getSort();
        self::assertCount(1, $sort);
        self::assertSame('net_sales', $sort[0]->by);
        self::assertSame('desc', $sort[0]->direction);

        self::assertSame(50, $request->getLimit());
        self::assertSame(10, $request->getOffset());
        self::assertSame([1, 2], $request->getStoreIds());
        self::assertSame('mysql', $request->getSource());
        self::assertTrue($request->isBypassCache());
    }

    public function testItAppliesDefaultsForMissingOptionalKeys(): void
    {
        $request = $this->factory->fromArray([
            'dataset' => 'sales_orders',
            'metrics' => ['orders'],
        ]);

        self::assertNull($request->getPreset());
        self::assertSame([], $request->getDimensions());
        self::assertSame([], $request->getFilters());
        self::assertSame(DateRangeType::LAST_30_DAYS, $request->getDateRange()->type);
        self::assertNull($request->getDateRange()->from);
        self::assertNull($request->getDateRange()->to);
        self::assertSame(CompareMode::NONE, $request->getCompareTo());
        self::assertSame([], $request->getSort());
        self::assertNull($request->getLimit());
        self::assertSame(0, $request->getOffset());
        self::assertSame([], $request->getStoreIds());
        self::assertNull($request->getSource());
        self::assertFalse($request->isBypassCache());
    }

    public function testItRoundTripsARequestThroughToArrayAndFromArray(): void
    {
        $original = $this->factory->fromArray($this->completePayload());

        $roundTripped = $this->factory->fromArray($this->factory->toArray($original));

        self::assertEquals($original, $roundTripped);
        self::assertSame('sales_overview', $roundTripped->getPreset());
    }

    /**
     * @param array<string,mixed> $payload
     */
    #[DataProvider('missingRequiredKeyProvider')]
    public function testItRejectsPayloadsMissingDatasetOrMetrics(array $payload): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->factory->fromArray($payload);
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function missingRequiredKeyProvider(): array
    {
        return [
            'missing dataset' => [['metrics' => ['orders']]],
            'missing metrics' => [['dataset' => 'sales_orders']],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    #[DataProvider('unknownVocabularyProvider')]
    public function testItRejectsUnknownDateRangeTypesAndCompareModes(array $payload): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->factory->fromArray($payload);
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function unknownVocabularyProvider(): array
    {
        return [
            'unknown date range type' => [[
                'dataset' => 'sales_orders',
                'metrics' => ['orders'],
                'dateRange' => ['type' => 'not_a_range'],
            ]],
            'unknown compare mode' => [[
                'dataset' => 'sales_orders',
                'metrics' => ['orders'],
                'compareTo' => 'not_a_mode',
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $dateRange
     */
    #[DataProvider('invalidCustomDateRangeProvider')]
    public function testItRejectsCustomDateRangesWithoutValidFromAndToDates(array $dateRange): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->factory->fromArray([
            'dataset' => 'sales_orders',
            'metrics' => ['orders'],
            'dateRange' => $dateRange,
        ]);
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function invalidCustomDateRangeProvider(): array
    {
        return [
            'missing from and to' => [['type' => 'custom']],
            'missing to' => [['type' => 'custom', 'from' => '2026-01-01']],
            'invalid from format' => [['type' => 'custom', 'from' => '2026/01/01', 'to' => '2026-01-31']],
            'non-existent calendar date' => [['type' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01']],
        ];
    }

    public function testItAcceptsACustomDateRangeWithValidFromAndToDates(): void
    {
        $request = $this->factory->fromArray([
            'dataset' => 'sales_orders',
            'metrics' => ['orders'],
            'dateRange' => ['type' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
        ]);

        self::assertSame(DateRangeType::CUSTOM, $request->getDateRange()->type);
        self::assertSame('2026-01-01', $request->getDateRange()->from);
        self::assertSame('2026-01-31', $request->getDateRange()->to);
    }

    /**
     * @return array<string,mixed>
     */
    private function completePayload(): array
    {
        return [
            'dataset' => 'sales_orders',
            'preset' => 'sales_overview',
            'metrics' => ['orders', 'net_sales'],
            'dimensions' => [
                ['code' => 'order_date', 'granularity' => 'day'],
                ['code' => 'channel', 'granularity' => null],
            ],
            'filters' => [
                ['field' => 'status', 'operator' => 'eq', 'value' => 'complete'],
                ['field' => 'store_id', 'operator' => 'in', 'value' => [1, 2]],
            ],
            'dateRange' => ['type' => 'last_30_days'],
            'compareTo' => 'previous_period',
            'sort' => [['by' => 'net_sales', 'direction' => 'desc']],
            'limit' => 50,
            'offset' => 10,
            'storeIds' => [1, 2],
            'source' => 'mysql',
            'bypassCache' => true,
        ];
    }
}
