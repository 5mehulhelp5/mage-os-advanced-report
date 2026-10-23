<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Api\PresetQueryInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Label\LabelResolverPool;
use MageOS\AdvancedReport\Model\Preset\LowStockCoverageQuery;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use MageOS\AdvancedReport\Model\Query\BaseCurrencyChecker;
use MageOS\AdvancedReport\Model\Query\ColumnMetadataBuilder;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\ComparisonMerger;
use MageOS\AdvancedReport\Model\Query\DateBucketFiller;
use MageOS\AdvancedReport\Model\Query\DateBucketPostProcessor;
use MageOS\AdvancedReport\Model\Query\DateBucketSequence;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\QueryPlanner;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ReportRunner;
use MageOS\AdvancedReport\Model\Query\ReportWarningsCollector;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Query\ResultCache;
use MageOS\AdvancedReport\Model\Query\SortOrder;
use MageOS\AdvancedReport\Model\Query\SourceResolver;
use MageOS\AdvancedReport\Model\Security\AclContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportRunner::class)]
final class ReportRunnerTest extends TestCase
{
    private DefinitionRegistry $definitionRegistry;

    private PresetQueryPool $presetQueryPool;

    private ResultCache $resultCache;

    private Config $config;

    private DateRangeResolver $dateRangeResolver;

    private ReportWarningsCollector $warningsCollector;

    private BaseCurrencyChecker $baseCurrencyChecker;

    private LocaleResolverInterface $localeResolver;

    private AclContext $aclContext;

    private Dataset $dataset;

    protected function setUp(): void
    {
        $this->dataset = $this->buildDataset();

        $this->definitionRegistry = $this->createStub(DefinitionRegistry::class);
        $this->definitionRegistry->method('getDataset')->willReturn($this->dataset);
        $this->definitionRegistry->method('getConfigVersion')->willReturn('config-v1');

        $this->presetQueryPool = $this->createStub(PresetQueryPool::class);

        $this->resultCache = $this->createStub(ResultCache::class);
        $this->resultCache->method('buildKey')->willReturn('cache-key');
        $this->resultCache->method('load')->willReturn(null);

        $this->config = $this->createStub(Config::class);
        $this->config->method('getMaxRows')->willReturn(500);
        $this->config->method('getCountedStatuses')->willReturn([]);
        $this->config->method('getFirstDayOfWeek')->willReturn(0);

        $this->dateRangeResolver = $this->createStub(DateRangeResolver::class);

        $this->warningsCollector = $this->createStub(ReportWarningsCollector::class);
        $this->warningsCollector->method('collect')->willReturn([]);

        $this->baseCurrencyChecker = $this->createStub(BaseCurrencyChecker::class);
        $this->baseCurrencyChecker->method('getCurrencyCode')->willReturn('USD');

        $this->localeResolver = $this->createStub(LocaleResolverInterface::class);
        $this->localeResolver->method('getLocale')->willReturn('en_US');

        $this->aclContext = $this->createStub(AclContext::class);
        $this->aclContext->method('getFingerprint')->willReturn('acl-fp');
    }

    public function testItPassesTheAclFingerprintToTheCacheKeyFromTheRunner(): void
    {
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->expects(self::once())->method('buildKey')->with(
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            'acl-fp',
        )->willReturn('cache-key');
        $resultCache->method('load')->willReturn([
            'columns' => [], 'rows' => [], 'totals' => ['metrics' => []], 'totalRowCount' => 0,
            'range' => null, 'comparisonRange' => null, 'currencyCode' => 'USD', 'warnings' => [],
            'source' => 'mysql', 'generatedAt' => '2026-09-25T10:00:00+00:00', 'meta' => [],
        ]);

        $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'resultCache' => $resultCache,
        ])->run($this->buildRequest());
    }

    public function testItReturnsACachedResultFlaggedAsCachedWithoutExecutingTheSource(): void
    {
        $request = $this->buildRequest();
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);

        $dataSource = $this->createMock(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->expects(self::never())->method('execute');
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $payload = [
            'columns' => [],
            'rows' => [['dims' => [], 'dims_raw' => [], 'metrics' => ['net_sales' => 42.0]]],
            'totals' => ['metrics' => ['net_sales' => 42.0]],
            'totalRowCount' => 1,
            'range' => ['from' => '2026-09-01', 'to' => '2026-09-25', 'includesToday' => true, 'days' => 25],
            'comparisonRange' => null,
            'currencyCode' => 'USD',
            'warnings' => [],
            'source' => 'mysql',
            'generatedAt' => '2026-09-25T10:00:00+00:00',
            'meta' => [],
        ];
        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->method('buildKey')->willReturn('cache-key');
        $resultCache->expects(self::once())->method('load')->with('cache-key')->willReturn($payload);
        $resultCache->expects(self::never())->method('save');

        $runner = $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'resultCache' => $resultCache,
        ]);

        $result = $runner->run($request);

        self::assertTrue($result->isCached());
        self::assertSame('mysql', $result->getSource());
        self::assertSame($payload['rows'], $result->getRows());
    }

    public function testItBypassesTheCacheWhenRequested(): void
    {
        $request = $this->buildRequest(bypassCache: true);
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);

        $dataSource = $this->createMock(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->expects(self::once())->method('execute')->willReturn(
            new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 100.0]], totalRowCount: 0),
        );
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->method('buildKey')->willReturn('cache-key');
        $resultCache->expects(self::never())->method('load');
        $resultCache->expects(self::once())->method('save');

        $runner = $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'resultCache' => $resultCache,
        ]);

        $result = $runner->run($request);

        self::assertFalse($result->isCached());
        self::assertSame(['net_sales' => 100.0], $result->getTotals()['metrics']);
    }

    public function testItExecutesTheComparisonPeriodWithoutLimitAndOffsetAndMergesIt(): void
    {
        $request = $this->buildRequest(metrics: ['net_sales'], compareTo: CompareMode::PREVIOUS_PERIOD, limit: 10, offset: 5);
        $mainQuery = $this->buildResolvedQuery(['net_sales'], limit: 10, offset: 5);
        $comparisonQuery = $this->buildResolvedQuery(
            ['net_sales'],
            range: $this->resolvedRange('2026-08-01', '2026-08-25'),
            limit: 10,
            offset: 5,
        );
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => $comparisonQuery]);

        $seenQueries = [];
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willReturnCallback(
            function (ReportRequestInterface $req, ResolvedQuery $query) use (&$seenQueries): RawResult {
                $seenQueries[] = $query;

                return new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 100.0]], totalRowCount: 0);
            }
        );
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $comparisonMerger = $this->createMock(ComparisonMerger::class);
        $comparisonMerger->expects(self::once())
            ->method('mergeRows')
            ->with([], [], ['net_sales'], null)
            ->willReturn([]);
        $comparisonMerger->expects(self::once())
            ->method('mergeTotals')
            ->with(
                ['metrics' => ['net_sales' => 100.0]],
                ['metrics' => ['net_sales' => 100.0]],
                ['net_sales'],
            )
            ->willReturn(['metrics' => ['net_sales' => 100.0], 'comparison' => ['net_sales' => ['value' => 100.0, 'delta' => 0.0, 'delta_pct' => 0.0]]]);

        $runner = $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'comparisonMerger' => $comparisonMerger,
        ]);

        $result = $runner->run($request);

        self::assertCount(2, $seenQueries);
        self::assertSame(10, $seenQueries[0]->limit);
        self::assertSame(5, $seenQueries[0]->offset);
        self::assertSame(500, $seenQueries[1]->limit);
        self::assertSame(0, $seenQueries[1]->offset);
        self::assertArrayHasKey('comparison', $result->getTotals());
    }

    public function testItDispatchesPresetsWithAPresetQueryThroughThePresetQueryPool(): void
    {
        $request = $this->buildRequest(preset: 'low_stock');
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);

        $preset = new Preset(
            code: 'low_stock',
            area: 'catalog',
            label: 'Low stock coverage',
            dataset: 'sales_items',
            metrics: ['net_sales'],
            dimensions: [],
            filters: [],
            sort: [],
            compareTo: 'none',
            dateRange: null,
            sources: [],
            presetQuery: LowStockCoverageQuery::class,
        );
        $definitionRegistry = $this->createStub(DefinitionRegistry::class);
        $definitionRegistry->method('getDataset')->willReturn($this->dataset);
        $definitionRegistry->method('getConfigVersion')->willReturn('config-v1');
        $definitionRegistry->method('getPreset')->willReturn($preset);

        $presetQuery = $this->createMock(PresetQueryInterface::class);
        $presetQuery->expects(self::once())->method('execute')->willReturn(
            new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 5.0]], totalRowCount: 0),
        );
        $presetQueryPool = $this->createMock(PresetQueryPool::class);
        $presetQueryPool->expects(self::once())
            ->method('get')
            ->with(LowStockCoverageQuery::class)
            ->willReturn($presetQuery);

        $sourceResolver = $this->createMock(SourceResolver::class);
        $sourceResolver->expects(self::never())->method('resolve');

        $runner = $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'definitionRegistry' => $definitionRegistry,
            'presetQueryPool' => $presetQueryPool,
            'sourceResolver' => $sourceResolver,
        ]);

        $result = $runner->run($request);

        self::assertSame('low_stock', $result->getSource());
    }

    public function testItFillsEmptyDateBucketsAndPaginatesInPhpForSingleDateDimensionRequests(): void
    {
        $request = $this->buildRequest(
            metrics: ['net_sales'],
            dimensions: [new DimensionSelection('order_date', Granularity::DAY)],
            sort: [new SortOrder('order_date', 'desc')],
            limit: 2,
            offset: 0,
        );
        $range = $this->resolvedRange('2026-03-10', '2026-03-12');
        $mainQuery = $this->buildResolvedQuery(['net_sales'], range: $range, limit: 10, offset: 0);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);

        $rawRows = [
            ['dims' => ['order_date' => '2026-03-10'], 'dims_raw' => ['order_date' => '2026-03-10'], 'metrics' => ['net_sales' => 100.0]],
            ['dims' => ['order_date' => '2026-03-12'], 'dims_raw' => ['order_date' => '2026-03-12'], 'metrics' => ['net_sales' => 60.0]],
        ];
        $dataSource = $this->createMock(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn (ResolvedQuery $query): bool => $query->limit === null && $query->offset === 0),
            )
            ->willReturn(new RawResult(rows: $rawRows, totals: ['metrics' => ['net_sales' => 160.0]], totalRowCount: 2));
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $bucketPostProcessor = new DateBucketPostProcessor(new DateBucketFiller(new DateBucketSequence()), new LabelResolverPool());
        $runner = $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'dateBucketPostProcessor' => $bucketPostProcessor,
        ]);

        $result = $runner->run($request);

        self::assertSame(3, $result->getTotalRowCount());
        self::assertCount(2, $result->getRows());
        self::assertSame('2026-03-12', $result->getRows()[0]['dims_raw']['order_date']);
        self::assertSame(60.0, $result->getRows()[0]['metrics']['net_sales']);
        self::assertSame('2026-03-11', $result->getRows()[1]['dims_raw']['order_date']);
        self::assertSame(0, $result->getRows()[1]['metrics']['net_sales']);
    }

    public function testItComputesCostCoverageForTheWarningAndStripsItFromTheOutputWhenNotRequested(): void
    {
        $request = $this->buildRequest(metrics: ['gross_margin']);
        $mainQuery = $this->buildResolvedQuery(['net_sales', 'cost']);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);

        $seenQuery = null;
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willReturnCallback(
            function (ReportRequestInterface $req, ResolvedQuery $query) use (&$seenQuery): RawResult {
                $seenQuery = $query;

                return new RawResult(
                    rows: [],
                    totals: ['metrics' => ['net_sales' => 1000.0, 'cost' => 400.0, 'has_cost_rows' => 5, 'rows' => 10]],
                    totalRowCount: 0,
                );
            }
        );
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $warningsCollector = $this->createMock(ReportWarningsCollector::class);
        $warningsCollector->expects(self::once())
            ->method('collect')
            ->with($this->dataset, $request, ['gross_margin'], 0.5)
            ->willReturn(['Cost data is known for only 50% of the reported rows.']);

        $runner = $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'warningsCollector' => $warningsCollector,
        ]);

        $result = $runner->run($request);

        self::assertInstanceOf(ResolvedQuery::class, $seenQuery);
        self::assertSame(['net_sales', 'cost', 'has_cost_rows', 'rows'], $seenQuery->metrics);
        self::assertSame(['gross_margin'], array_keys($result->getTotals()['metrics']));
        self::assertSame(600.0, $result->getTotals()['metrics']['gross_margin']);
        self::assertSame(['Cost data is known for only 50% of the reported rows.'], $result->getWarnings());
    }

    /**
     * @param array<string,mixed> $overrides Must include `queryPlanner` and `sourceResolver`,
     *                                       each test configures its own `plan()`/`resolve()`.
     */
    private function buildRunner(array $overrides): ReportRunner
    {
        return new ReportRunner(
            $overrides['queryPlanner'],
            $overrides['definitionRegistry'] ?? $this->definitionRegistry,
            $overrides['sourceResolver'],
            $overrides['presetQueryPool'] ?? $this->presetQueryPool,
            $overrides['derivedMetricCalculator'] ?? new DerivedMetricCalculator(),
            $overrides['comparisonMerger'] ?? new ComparisonMerger(new DateBucketSequence()),
            $overrides['columnMetadataBuilder'] ?? new ColumnMetadataBuilder(),
            $overrides['resultCache'] ?? $this->resultCache,
            $this->config,
            $this->dateRangeResolver,
            $overrides['dateBucketPostProcessor'] ?? new DateBucketPostProcessor(new DateBucketFiller(new DateBucketSequence()), new LabelResolverPool()),
            $overrides['warningsCollector'] ?? $this->warningsCollector,
            $this->baseCurrencyChecker,
            $this->localeResolver,
            $overrides['aclContext'] ?? $this->aclContext,
        );
    }

    /**
     * @param string[] $metrics
     * @param DimensionSelection[] $dimensions
     * @param SortOrder[] $sort
     */
    private function buildRequest(
        array $metrics = ['net_sales'],
        array $dimensions = [],
        CompareMode $compareTo = CompareMode::NONE,
        ?int $limit = null,
        int $offset = 0,
        array $sort = [],
        ?string $preset = null,
        bool $bypassCache = false,
    ): ReportRequestInterface {
        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getDataset')->willReturn('sales_items');
        $request->method('getPreset')->willReturn($preset);
        $request->method('getMetrics')->willReturn($metrics);
        $request->method('getDimensions')->willReturn($dimensions);
        $request->method('getFilters')->willReturn([]);
        $request->method('getDateRange')->willReturn(new DateRange(DateRangeType::LAST_30_DAYS));
        $request->method('getCompareTo')->willReturn($compareTo);
        $request->method('getSort')->willReturn($sort);
        $request->method('getLimit')->willReturn($limit);
        $request->method('getOffset')->willReturn($offset);
        $request->method('getStoreIds')->willReturn([]);
        $request->method('getSource')->willReturn(null);
        $request->method('isBypassCache')->willReturn($bypassCache);

        return $request;
    }

    /**
     * @param string[] $metrics
     */
    private function buildResolvedQuery(
        array $metrics,
        ?ResolvedDateRange $range = null,
        ?int $limit = null,
        int $offset = 0,
    ): ResolvedQuery {
        return new ResolvedQuery(
            dataset: $this->dataset,
            metrics: $metrics,
            dimensions: [],
            filters: [],
            dateRange: $range ?? $this->resolvedRange('2026-09-01', '2026-09-25'),
            storeIds: [],
            sort: [],
            limit: $limit,
            offset: $offset,
        );
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
                'net_sales' => $this->aggregateMetric('net_sales', AggregateFunction::SUM),
                'cost' => $this->aggregateMetric('cost', AggregateFunction::SUM),
                'gross_margin' => new Metric(
                    code: 'gross_margin',
                    label: 'Gross margin',
                    description: null,
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: false,
                    formula: 'net_sales - cost',
                ),
                'margin_pct' => new Metric(
                    code: 'margin_pct',
                    label: 'Margin %',
                    description: null,
                    format: 'percent',
                    hidden: false,
                    lowerIsBetter: false,
                    formula: 'gross_margin / net_sales',
                ),
                'has_cost_rows' => $this->aggregateMetric('has_cost_rows', AggregateFunction::SUM, hidden: true),
                'rows' => $this->aggregateMetric('rows', AggregateFunction::COUNT, hidden: true),
                'cost_coverage' => new Metric(
                    code: 'cost_coverage',
                    label: 'Cost coverage',
                    description: null,
                    format: 'percent',
                    hidden: false,
                    lowerIsBetter: false,
                    formula: 'has_cost_rows / rows',
                ),
            ],
            dimensions: [
                'order_date' => new Dimension(
                    code: 'order_date',
                    label: 'Order date',
                    fieldCode: 'created_at',
                    granularities: [Granularity::DAY],
                ),
                'category_id' => new Dimension(
                    code: 'category_id',
                    label: 'Category',
                    fieldCode: 'category_id',
                    nonAdditive: true,
                ),
            ],
        );
    }

    private function aggregateMetric(string $code, AggregateFunction $aggregate, bool $hidden = false): Metric
    {
        return new Metric(
            code: $code,
            label: $code,
            description: null,
            format: 'number',
            hidden: $hidden,
            lowerIsBetter: false,
            aggregate: $aggregate,
            fieldCode: $code,
        );
    }
}
