<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Preset;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\DataSourcePool;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Preset\BundleChildUnitsLoader;
use MageOS\AdvancedReport\Model\Preset\LowStockCoverageQuery;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Security\DatasetAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LowStockCoverageQuery::class)]
final class LowStockCoverageQueryTest extends TestCase
{
    public function testItIncludesCoverageSalesDataWhenTheSalesDatasetIsAccessible(): void
    {
        $runner = $this->createMock(ReportRunnerInterface::class);
        $runner->expects(self::once())->method('run')->with(self::callback(
            static fn (ReportRequestInterface $request): bool => $request->getDataset() === 'sales_items'
                && $request->getMetrics() === ['units_sold']
                && $request->getDimensions()[0]->code === 'sku'
                && $request->getDateRange()->type->value === 'last_30_days'
                && $request->getLimit() === 5000
                && $request->getStoreIds() === [2]
        ))->willReturn($this->salesResult([['sku' => 'A', 'units' => 60.0], ['sku' => 'B', 'units' => 30.0]]));

        $query = $this->createQuery([['A', 10.0], ['B', 30.0], ['C', 0.0]], $runner, true);
        $result = $query->execute($this->request([2]), $this->resolvedQuery([2]));

        self::assertSame(['A', 'B'], array_column(array_column($result->rows, 'dims_raw'), 'sku'));
        self::assertSame(60.0, $result->rows[0]['metrics']['units_sold_30d']);
        self::assertSame(2.0, $result->rows[0]['metrics']['avg_daily_sales']);
        self::assertSame(5.0, $result->rows[0]['metrics']['coverage_days']);
        self::assertSame(30.0, $result->rows[1]['metrics']['coverage_days']);
        self::assertSame(2, $result->totalRowCount);
        self::assertSame([], $result->warnings);
    }

    public function testItSumsTheStockAndNestedSalesReportStatements(): void
    {
        $sales = $this->createStub(ReportResultInterface::class);
        $sales->method('getRows')->willReturn([]);
        $sales->method('getMeta')->willReturn(['sql_query_count' => 4]);
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willReturn($sales);

        $result = $this->createQuery([['A', 10.0]], $runner, true)->execute($this->request([]), $this->resolvedQuery([]));

        self::assertSame(8, $result->meta['sql_query_count']);
    }

    public function testItReturnsNullCoverageForProductsWithoutSales(): void
    {
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willReturn($this->salesResult([['sku' => 'A', 'units' => 30.0]]));

        $query = $this->createQuery([['NOSALE', 4.0], ['A', 10.0]], $runner, true);
        $result = $query->execute($this->request([]), $this->resolvedQuery([]));

        self::assertSame('A', $result->rows[0]['dims_raw']['sku']);
        self::assertSame('NOSALE', $result->rows[1]['dims_raw']['sku']);
        self::assertNull($result->rows[1]['metrics']['coverage_days']);
        self::assertSame(0.0, $result->rows[1]['metrics']['avg_daily_sales']);
        self::assertSame(0.0, $result->rows[1]['metrics']['units_sold_30d']);
    }

    public function testItOmitsCoverageSalesDataWhenTheSalesDatasetIsNotAccessible(): void
    {
        $runner = $this->createMock(ReportRunnerInterface::class);
        $runner->expects(self::never())->method('run');

        $query = $this->createQuery([['A', 10.0]], $runner, false);
        $result = $query->execute($this->request([]), $this->resolvedQuery([]));

        self::assertSame(10.0, $result->rows[0]['metrics']['qty_on_hand']);
        self::assertNull($result->rows[0]['metrics']['units_sold_30d']);
        self::assertNull($result->rows[0]['metrics']['avg_daily_sales']);
        self::assertNull($result->rows[0]['metrics']['coverage_days']);
        self::assertCount(1, $result->warnings);
    }

    public function testItRequestsOnlySkuFromTheStockSourceByDefault(): void
    {
        /** @var \ArrayObject<int,ResolvedQuery> $captured */
        $captured = new \ArrayObject();
        $query = $this->createQuery([['A', 10.0]], $this->createStub(ReportRunnerInterface::class), false, $captured);
        $query->execute($this->request([]), $this->resolvedQuery([]));

        self::assertSame(['sku'], array_map(
            static fn (DimensionSelection $dimension): string => $dimension->code,
            self::firstCaptured($captured)->dimensions
        ));
    }

    public function testItForwardsTheProductDimensionToTheStockSource(): void
    {
        $product = new DimensionSelection('product_id');
        /** @var \ArrayObject<int,ResolvedQuery> $captured */
        $captured = new \ArrayObject();
        $query = $this->createQuery([['A', 10.0]], $this->createStub(ReportRunnerInterface::class), false, $captured);
        $query->execute($this->request([]), $this->resolvedQuery([], [$product, new DimensionSelection('sku')]));

        self::assertCount(2, self::firstCaptured($captured)->dimensions);
        self::assertSame('sku', self::firstCaptured($captured)->dimensions[0]->code);
        self::assertSame($product, self::firstCaptured($captured)->dimensions[1]);
    }

    public function testItIgnoresOtherRequestedDimensions(): void
    {
        /** @var \ArrayObject<int,ResolvedQuery> $captured */
        $captured = new \ArrayObject();
        $query = $this->createQuery([['A', 10.0]], $this->createStub(ReportRunnerInterface::class), false, $captured);
        $query->execute(
            $this->request([]),
            $this->resolvedQuery([], [new DimensionSelection('source_code'), new DimensionSelection('sku')])
        );

        self::assertSame(['sku'], array_map(
            static fn (DimensionSelection $dimension): string => $dimension->code,
            self::firstCaptured($captured)->dimensions
        ));
    }

    public function testItKeepsProductLabelsFromTheStockRowsInTheResult(): void
    {
        $query = $this->createQuery(
            [['A', 10.0]],
            $this->createStub(ReportRunnerInterface::class),
            false,
            null,
            ['A' => ['Widget', '42']]
        );
        $result = $query->execute(
            $this->request([]),
            $this->resolvedQuery([], [new DimensionSelection('product_id')])
        );

        self::assertSame('Widget', $result->rows[0]['dims']['product_id']);
        self::assertSame('42', $result->rows[0]['dims_raw']['product_id']);
    }

    public function testItStillMatchesUnitsSoldBySku(): void
    {
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willReturn($this->salesResult([['sku' => 'A', 'units' => 60.0]]));
        $query = $this->createQuery([['A', 10.0]], $runner, true, null, ['A' => ['Widget', '42']]);
        $result = $query->execute(
            $this->request([]),
            $this->resolvedQuery([], [new DimensionSelection('product_id')])
        );

        self::assertSame(60.0, $result->rows[0]['metrics']['units_sold_30d']);
    }

    public function testItPropagatesTheTruncationWarningWhenTheInnerStockQueryHitsMaxRows(): void
    {
        $warning = 'Results were truncated to the first 5000 rows. Narrow the date range or add filters.';
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willReturn($this->salesResult([]));

        $query = $this->createQuery([['A', 10.0]], $runner, true, null, [], [$warning]);
        $result = $query->execute($this->request([]), $this->resolvedQuery([]));

        self::assertSame([$warning], $result->warnings);
    }

    public function testItPropagatesTheTruncationWarningWhenTheInnerSalesQueryHitsMaxRows(): void
    {
        $warning = 'Results were truncated to the first 5000 rows. Narrow the date range or add filters.';
        $sales = $this->createStub(ReportResultInterface::class);
        $sales->method('getRows')->willReturn([]);
        $sales->method('getWarnings')->willReturn([$warning]);
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willReturn($sales);

        $result = $this->createQuery([['A', 10.0]], $runner, true)->execute($this->request([]), $this->resolvedQuery([]));

        self::assertSame([$warning], $result->warnings);
    }

    public function testItAddsTheUnitsSoldInsideBundlesToTheTopLevelUnits(): void
    {
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willReturn($this->salesResult([['sku' => 'A', 'units' => 60.0]]));
        $bundleWarning = 'bundle truncated';

        $query = $this->createQuery([['A', 10.0], ['B', 10.0]], $runner, true, null, [], [], ['A' => 30.0, 'B' => 3.0], [$bundleWarning]);
        $result = $query->execute($this->request([2]), $this->resolvedQuery([2]));

        self::assertSame(90.0, $result->rows[0]['metrics']['units_sold_30d']);
        self::assertSame(3.0, $result->rows[1]['metrics']['units_sold_30d']);
        self::assertSame([$bundleWarning], $result->warnings);
    }

    /**
     * @param \ArrayObject<int,ResolvedQuery> $captured
     */
    private static function firstCaptured(\ArrayObject $captured): ResolvedQuery
    {
        $query = $captured[0];
        self::assertInstanceOf(ResolvedQuery::class, $query);

        return $query;
    }

    /**
     * @param array<int,array{0:string,1:float}> $stock
     * @param \ArrayObject<int,ResolvedQuery>|null $captured Receives the queries sent to the stock source.
     * @param array<string,array{0:string,1:string}> $products Product name and id by SKU.
     * @param string[] $stockWarnings Warnings of the stock source.
     * @param array<string,float> $bundleUnits Units sold inside bundles, by SKU.
     * @param string[] $bundleWarnings
     */
    private function createQuery(
        array $stock,
        ReportRunnerInterface $runner,
        bool $salesAllowed,
        ?\ArrayObject $captured = null,
        array $products = [],
        array $stockWarnings = [],
        array $bundleUnits = [],
        array $bundleWarnings = [],
    ): LowStockCoverageQuery {
        $rows = array_map(
            static fn (array $item): array => [
                'dims' => ['sku' => $item[0]] + (isset($products[$item[0]]) ? ['product_id' => $products[$item[0]][0]] : []),
                'dims_raw' => ['sku' => $item[0]] + (isset($products[$item[0]]) ? ['product_id' => $products[$item[0]][1]] : []),
                'metrics' => ['qty_on_hand' => $item[1]],
            ],
            $stock
        );
        $source = $this->createStub(DataSourceInterface::class);
        $source->method('execute')->willReturnCallback(
            static function (ReportRequestInterface $request, ResolvedQuery $query) use ($rows, $captured, $stockWarnings): RawResult {
                $captured?->append($query);

                return new RawResult($rows, ['metrics' => []], \count($rows), $stockWarnings, ['sql_query_count' => 3]);
            }
        );
        $pool = $this->createStub(DataSourcePool::class);
        $pool->method('get')->willReturn($source);

        $salesDataset = new Dataset('sales_items', 'Items', 'acl', 'sales', false, null, null, null, [], [], [], [], []);
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getDataset')->willReturn($salesDataset);
        $datasetAccess = $this->createStub(DatasetAccess::class);
        $datasetAccess->method('isAllowed')->willReturnCallback(
            static fn (Dataset $dataset): bool => $dataset === $salesDataset && $salesAllowed
        );
        $config = $this->createStub(Config::class);
        $config->method('getMaxRows')->willReturn(5000);

        $bundleLoader = $this->createStub(BundleChildUnitsLoader::class);
        $bundleLoader->method('load')->willReturn(['units' => $bundleUnits, 'warnings' => $bundleWarnings]);

        return new LowStockCoverageQuery(
            $pool,
            $runner,
            new ReportRequestFactory(),
            $datasetAccess,
            $registry,
            $config,
            $bundleLoader,
        );
    }

    /**
     * @param array<int,array{sku:string,units:float}> $sales
     */
    private function salesResult(array $sales): ReportResultInterface
    {
        $result = $this->createStub(ReportResultInterface::class);
        $result->method('getRows')->willReturn(array_map(
            static fn (array $row): array => [
                'dims' => ['sku' => $row['sku']],
                'dims_raw' => ['sku' => $row['sku']],
                'metrics' => ['units_sold' => $row['units']],
            ],
            $sales
        ));

        return $result;
    }

    /**
     * @param int[] $storeIds
     */
    private function request(array $storeIds): ReportRequestInterface
    {
        return (new ReportRequestFactory())->fromArray([
            'dataset' => 'catalog_stock',
            'preset' => 'low_stock_coverage',
            'metrics' => ['qty_on_hand', 'units_sold_30d', 'avg_daily_sales', 'coverage_days'],
            'dimensions' => [['code' => 'sku', 'granularity' => null]],
            'storeIds' => $storeIds,
        ]);
    }

    /**
     * @param int[] $storeIds
     * @param DimensionSelection[]|null $dimensions
     */
    private function resolvedQuery(array $storeIds, ?array $dimensions = null): ResolvedQuery
    {
        return new ResolvedQuery(
            new Dataset('catalog_stock', 'Stock', 'acl', 'catalog', true, null, null, null, [], [], [], [], []),
            ['qty_on_hand'],
            $dimensions ?? [new DimensionSelection('sku')],
            [],
            null,
            $storeIds,
            [],
            null,
            0,
            new ReportScope(null, 'UTC', 0, ['UTC']),
        );
    }
}
