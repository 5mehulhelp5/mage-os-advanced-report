<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use ArrayObject;
use DateTimeImmutable;
use LogicException;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Api\PresetQueryInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\CurrencyProbeInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
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
use MageOS\AdvancedReport\Model\Query\ComparisonPeriod;
use MageOS\AdvancedReport\Model\Query\DateBucketFiller;
use MageOS\AdvancedReport\Model\Query\DateBucketPostProcessor;
use MageOS\AdvancedReport\Model\Query\DateBucketSequence;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\MonetaryRequestDetector;
use MageOS\AdvancedReport\Model\Query\QueryPlanner;
use MageOS\AdvancedReport\Model\Query\QuoteRetention;
use MageOS\AdvancedReport\Model\Query\QuoteRetentionWindow;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ReportRunner;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ReportWarningsCollector;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Query\ResultCache;
use MageOS\AdvancedReport\Model\Query\ResultCacheLock;
use MageOS\AdvancedReport\Model\Query\ResultCacheWriteGuard;
use MageOS\AdvancedReport\Model\Query\ResultConfigFingerprint;
use MageOS\AdvancedReport\Model\Query\SortOrder;
use MageOS\AdvancedReport\Model\Query\SourceResolver;
use MageOS\AdvancedReport\Model\Security\AclContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    private MonetaryRequestDetector $monetaryRequestDetector;

    private CurrencyProbeInterface $currencyProbe;

    private LocaleResolverInterface $localeResolver;

    private AclContext $aclContext;

    private ResultConfigFingerprint $resultConfigFingerprint;

    private QuoteRetention $quoteRetention;

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
        $this->baseCurrencyChecker->method('resolveCurrencyCode')->willReturn('USD');

        $this->monetaryRequestDetector = $this->createStub(MonetaryRequestDetector::class);
        $this->monetaryRequestDetector->method('isMonetary')->willReturn(true);

        $this->currencyProbe = $this->createStub(CurrencyProbeInterface::class);
        $this->currencyProbe->method('getBaseCurrencies')->willReturn([]);

        $this->localeResolver = $this->createStub(LocaleResolverInterface::class);
        $this->localeResolver->method('getLocale')->willReturn('en_US');

        $this->aclContext = $this->createStub(AclContext::class);
        $this->aclContext->method('getFingerprint')->willReturn('acl-fp');

        $this->resultConfigFingerprint = $this->createStub(ResultConfigFingerprint::class);
        $this->resultConfigFingerprint->method('get')->willReturn([]);

        $this->quoteRetention = $this->createStub(QuoteRetention::class);
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

    public function testItDoesNotServeACachedResultWithAStaleRetentionWarningAfterTheWindowMoves(): void
    {
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willReturn(
            new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 1.0]], totalRowCount: 0)
        );
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $window = new QuoteRetentionWindow(30, new DateTimeImmutable('2026-02-13'));
        $retention = $this->createStub(QuoteRetention::class);
        $retention->method('window')->willReturn($window);

        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->expects(self::once())->method('buildKey')->with(
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            ['maxRows' => 500, 'quoteRetentionCutoff' => '2026-02-13'],
        )->willReturn('cache-key');
        $resultCache->method('load')->willReturn(null);

        $fingerprint = $this->createStub(ResultConfigFingerprint::class);
        $fingerprint->method('get')->willReturn(['maxRows' => 500]);

        $warningsCollector = $this->createMock(ReportWarningsCollector::class);
        $warningsCollector->expects(self::once())->method('collect')->with(
            $this->dataset,
            self::anything(),
            self::anything(),
            self::anything(),
            $window,
            $mainQuery->dateRange,
            null,
        )->willReturn(['retention']);

        $result = $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'resultCache' => $resultCache,
            'resultConfigFingerprint' => $fingerprint,
            'quoteRetention' => $retention,
            'warningsCollector' => $warningsCollector,
        ])->run($this->buildRequest());

        self::assertSame(['retention'], $result->getWarnings());
    }

    public function testItPassesTheResultConfigFingerprintToTheCacheKeyBuilder(): void
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
            self::anything(),
            self::anything(),
            ['maxRows' => 500],
        )->willReturn('cache-key');
        $resultCache->method('load')->willReturn([
            'columns' => [], 'rows' => [], 'totals' => ['metrics' => []], 'totalRowCount' => 0,
            'range' => null, 'comparisonRange' => null, 'currencyCode' => 'USD', 'warnings' => [],
            'source' => 'mysql', 'generatedAt' => '2026-09-25T10:00:00+00:00', 'meta' => [],
        ]);

        $fingerprint = $this->createStub(ResultConfigFingerprint::class);
        $fingerprint->method('get')->willReturn(['maxRows' => 500]);

        $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'resultCache' => $resultCache,
            'resultConfigFingerprint' => $fingerprint,
        ])->run($this->buildRequest());
    }

    public function testItPassesThePlannedScopeContextToTheResultCacheKey(): void
    {
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $scope = $mainQuery->scope;
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);
        $baseCurrencyChecker = $this->createStub(BaseCurrencyChecker::class);
        $baseCurrencyChecker->method('getCurrencyCodes')->willReturn(['EUR', 'USD']);

        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->expects(self::once())->method('buildKey')->with(
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            [
                'timezone' => $scope->timezone,
                'timezones' => $scope->timezones,
                'firstDayOfWeek' => $scope->firstDayOfWeek,
                'baseCurrencies' => ['EUR', 'USD'],
            ],
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
            'baseCurrencyChecker' => $baseCurrencyChecker,
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

    public function testItNeverRunsARowCountQueryForTheComparisonPeriod(): void
    {
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $comparisonQuery = $this->buildResolvedQuery(['net_sales'], range: $this->resolvedRange('2026-08-01', '2026-08-25'));
        $seenQueries = [];
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willReturnCallback(
            function (ReportRequestInterface $req, ResolvedQuery $query) use (&$seenQueries): RawResult {
                $seenQueries[] = $query;

                return new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 100.0]], totalRowCount: 0);
            }
        );

        $this->buildCurrencyRunner($mainQuery, $comparisonQuery, $dataSource)
            ->run($this->buildRequest(compareTo: CompareMode::PREVIOUS_PERIOD));

        self::assertTrue($seenQueries[0]->countRows);
        self::assertFalse($seenQueries[1]->countRows);
    }

    public function testItSumsSqlStatementsAndSqlTimeOfTheMainAndComparisonPeriods(): void
    {
        $results = [
            new RawResult([], ['metrics' => ['net_sales' => 1.0]], 0, [], ['sql_query_count' => 3, 'sql_time_ms' => 10.5]),
            new RawResult([], ['metrics' => ['net_sales' => 1.0]], 0, [], ['sql_query_count' => 2, 'sql_time_ms' => 4.5]),
        ];
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willReturnOnConsecutiveCalls(...$results);
        $probe = $this->createStub(CurrencyProbeInterface::class);
        $probe->method('getBaseCurrencies')->willReturn(['EUR']);

        $result = $this->buildCurrencyRunner(
            $this->buildResolvedQuery(['net_sales']),
            $this->buildResolvedQuery(['net_sales'], range: $this->resolvedRange('2026-08-01', '2026-08-25')),
            $dataSource,
            ['currencyProbe' => $probe],
        )->run($this->buildRequest(compareTo: CompareMode::PREVIOUS_PERIOD));

        self::assertSame(7, $result->getMeta()['sql_query_count']);
        self::assertSame(15.0, $result->getMeta()['sql_time_ms']);
    }

    public function testItReportsMissingSqlMetaAsZero(): void
    {
        $result = $this->buildCurrencyRunner(
            $this->buildResolvedQuery(['net_sales']),
            null,
            $this->buildCurrencyDataSource(),
        )->run($this->buildRequest());

        self::assertSame(1, $result->getMeta()['sql_query_count']);
        self::assertSame(0.0, $result->getMeta()['sql_time_ms']);
    }

    public function testItReportsZeroSqlStatementsForAResultServedFromCache(): void
    {
        $payload = $this->cachePayload();
        $payload['meta'] = ['timezone_strategy' => 'convert_tz', 'sql_query_count' => 5, 'sql_time_ms' => 12.5];
        $resultCache = $this->createStub(ResultCache::class);
        $resultCache->method('buildKey')->willReturn('cache-key');
        $resultCache->method('load')->willReturn($payload);

        $result = $this->buildCurrencyRunner(
            $this->buildResolvedQuery(['net_sales']),
            null,
            $this->buildCurrencyDataSource(),
            ['resultCache' => $resultCache],
        )->run($this->buildRequest());

        self::assertTrue($result->isCached());
        self::assertSame(0, $result->getMeta()['sql_query_count']);
        self::assertSame(0.0, $result->getMeta()['sql_time_ms']);
        self::assertSame(5, $result->getMeta()['cached_sql_query_count']);
        self::assertSame(12.5, $result->getMeta()['cached_sql_time_ms']);
        self::assertSame('convert_tz', $result->getMeta()['timezone_strategy']);
    }

    public function testItResolvesTheComparisonAlignmentPeriodWithTheMainQueryScopeInTheRunner(): void
    {
        $request = $this->buildRequest(
            dimensions: [new DimensionSelection('order_date', Granularity::DAY)],
            compareTo: CompareMode::PREVIOUS_PERIOD,
        );
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $comparisonRange = $this->resolvedRange('2026-08-07', '2026-08-31');
        $comparisonQuery = $this->buildResolvedQuery(['net_sales'], range: $comparisonRange);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => $comparisonQuery]);
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willReturn(
            new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 100.0]], totalRowCount: 0)
        );
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $dateRangeResolver = $this->createMock(DateRangeResolver::class);
        $dateRangeResolver->expects(self::once())
            ->method('previousPeriod')
            ->with($mainQuery->dateRange, $mainQuery->scope)
            ->willReturn(new ComparisonPeriod($comparisonRange, CompareMode::PREVIOUS_PERIOD, 25));
        $this->dateRangeResolver = $dateRangeResolver;

        $this->buildRunner(['queryPlanner' => $queryPlanner, 'sourceResolver' => $sourceResolver])->run($request);
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

    public function testItFillsAndAlignsWeekBucketsWithTheReportScopeFirstDayOfWeek(): void
    {
        $request = $this->buildRequest(
            metrics: ['net_sales'],
            dimensions: [new DimensionSelection('order_date', Granularity::WEEK)],
        );
        // 2026-03-10 is a Tuesday; the scope week starts on Monday (1) while the default config says Sunday (0)
        $range = $this->resolvedRange('2026-03-10', '2026-03-25');
        $mainQuery = $this->buildResolvedQuery(['net_sales'], range: $range);
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => null]);
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willReturn(
            new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 0]], totalRowCount: 0)
        );
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        $runner = $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'dateBucketPostProcessor' => new DateBucketPostProcessor(
                new DateBucketFiller(new DateBucketSequence()),
                new LabelResolverPool()
            ),
        ]);

        $keys = array_map(
            static fn (array $row): string => $row['dims_raw']['order_date'],
            $runner->run($request)->getRows()
        );

        self::assertSame(['2026-03-09', '2026-03-16', '2026-03-23'], $keys);
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

    public function testItRejectsAMonetaryRequestWhenTheMainPeriodOrdersUseDifferentBaseCurrencies(): void
    {
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $probe = $this->createStub(CurrencyProbeInterface::class);
        $probe->method('getBaseCurrencies')->willReturn(['EUR', 'USD']);
        $dataSource = $this->createMock(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->expects(self::never())->method('execute');

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage(
            'The orders in the report period use different base currencies (EUR, USD): amounts cannot be added without conversion.'
        );

        $this->buildCurrencyRunner($mainQuery, null, $dataSource, ['currencyProbe' => $probe])->run($this->buildRequest());
    }

    public function testItRejectsAMonetaryRequestWhenTheComparisonPeriodCurrencyDiffersFromTheMainPeriod(): void
    {
        $mainQuery = $this->buildResolvedQuery(['net_sales']);
        $comparisonQuery = $this->buildResolvedQuery(['net_sales'], range: $this->resolvedRange('2026-08-01', '2026-08-25'));
        $probe = $this->createStub(CurrencyProbeInterface::class);
        $probe->method('getBaseCurrencies')->willReturnCallback(
            static fn (ResolvedQuery $query): array => $query->dateRange?->from->format('m') === '08' ? ['USD'] : ['EUR']
        );
        $dataSource = $this->createMock(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->expects(self::never())->method('execute');

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('(EUR, USD)');

        $this->buildCurrencyRunner($mainQuery, $comparisonQuery, $dataSource, ['currencyProbe' => $probe])
            ->run($this->buildRequest(compareTo: CompareMode::PREVIOUS_PERIOD));
    }

    public function testItReportsTheProbedBaseCurrencyAsTheResultCurrencyCode(): void
    {
        $probe = $this->createStub(CurrencyProbeInterface::class);
        $probe->method('getBaseCurrencies')->willReturn(['EUR']);

        $result = $this->buildCurrencyRunner($this->buildResolvedQuery(['net_sales']), null, $this->buildCurrencyDataSource(), ['currencyProbe' => $probe])
            ->run($this->buildRequest());

        self::assertSame('EUR', $result->getCurrencyCode());
    }

    public function testItFallsBackToTheConfiguredBaseCurrencyWhenTheProbeFindsNoOrders(): void
    {
        $result = $this->buildCurrencyRunner($this->buildResolvedQuery(['net_sales']), null, $this->buildCurrencyDataSource())
            ->run($this->buildRequest());

        self::assertSame('USD', $result->getCurrencyCode());
    }

    public function testItDoesNotProbeCurrenciesForNonMonetaryRequests(): void
    {
        $detector = $this->createStub(MonetaryRequestDetector::class);
        $detector->method('isMonetary')->willReturn(false);
        $probe = $this->createMock(CurrencyProbeInterface::class);
        $probe->expects(self::never())->method('getBaseCurrencies');

        $this->buildCurrencyRunner(
            $this->buildResolvedQuery(['net_sales']),
            null,
            $this->buildCurrencyDataSource(),
            ['currencyProbe' => $probe, 'monetaryRequestDetector' => $detector],
        )->run($this->buildRequest());
    }

    public function testItDoesNotProbeCurrenciesForPresetQueryExecutions(): void
    {
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
        $presetQuery = $this->createStub(PresetQueryInterface::class);
        $presetQuery->method('execute')->willReturn(
            new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 5.0]], totalRowCount: 0),
        );
        $presetQueryPool = $this->createStub(PresetQueryPool::class);
        $presetQueryPool->method('get')->willReturn($presetQuery);
        $probe = $this->createMock(CurrencyProbeInterface::class);
        $probe->expects(self::never())->method('getBaseCurrencies');

        $this->buildCurrencyRunner(
            $this->buildResolvedQuery(['net_sales']),
            null,
            $this->buildCurrencyDataSource(),
            ['currencyProbe' => $probe, 'definitionRegistry' => $definitionRegistry, 'presetQueryPool' => $presetQueryPool],
        )->run($this->buildRequest(preset: 'low_stock'));
    }

    public function testItDoesNotCacheARejectedRequest(): void
    {
        $probe = $this->createStub(CurrencyProbeInterface::class);
        $probe->method('getBaseCurrencies')->willReturn(['EUR', 'USD']);
        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->method('buildKey')->willReturn('cache-key');
        $resultCache->method('load')->willReturn(null);
        $resultCache->expects(self::never())->method('save');

        try {
            $this->buildCurrencyRunner(
                $this->buildResolvedQuery(['net_sales']),
                null,
                $this->buildCurrencyDataSource(),
                ['currencyProbe' => $probe, 'resultCache' => $resultCache],
            )->run($this->buildRequest());
            self::fail('Expected InvalidRequestException');
        } catch (InvalidRequestException) {
            // expected
        }
    }

    public function testItValidatesFieldAclBeforeTheResultCacheIsRead(): void
    {
        $planner = $this->createStub(QueryPlanner::class);
        $planner->method('plan')->willThrowException(new AuthorizationException(new Phrase('denied')));
        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->expects(self::never())->method('buildKey');
        $resultCache->expects(self::never())->method('load');

        $this->expectException(AuthorizationException::class);

        $this->buildRunner([
            'queryPlanner' => $planner,
            'sourceResolver' => $this->createStub(SourceResolver::class),
            'resultCache' => $resultCache,
        ])->run($this->buildRequest());
    }

    public function testItCachesTimeRelativeDatasetsWithTheOpenPeriodTtlEvenForClosedRanges(): void
    {
        $this->assertSavedAsOpenPeriod(['sales_items'], true);
    }

    public function testItKeepsTheClosedPeriodTtlForOtherDatasetsOnClosedRanges(): void
    {
        $this->assertSavedAsOpenPeriod([], false);
    }

    /**
     * @param string[] $timeRelativeDatasets
     */
    private function assertSavedAsOpenPeriod(array $timeRelativeDatasets, bool $expectedOpenPeriod): void
    {
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn([
            'main' => $this->buildResolvedQuery(['net_sales']),
            'comparison' => null,
        ]);
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($this->executingSource(self::once()));
        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->method('buildKey')->willReturn('cache-key');
        $resultCache->method('load')->willReturn(null);
        $resultCache->expects(self::once())->method('save')->with('cache-key', self::anything(), $expectedOpenPeriod);

        $this->buildRunner([
            'queryPlanner' => $queryPlanner,
            'sourceResolver' => $sourceResolver,
            'resultCache' => $resultCache,
            'timeRelativeDatasets' => $timeRelativeDatasets,
        ])->run($this->buildRequest());
    }

    public function testItAcquiresALockNamedAfterTheCacheKeyOnACacheMiss(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $resultCacheLock = $this->buildLock($lockManager);
        $lockName = $resultCacheLock->getLockName('cache-key');
        $lockManager->expects(self::once())->method('lock')->with($lockName)->willReturn(true);
        $lockManager->expects(self::once())->method('unlock')->with($lockName)->willReturn(true);

        $result = $this->buildLockRunner($resultCacheLock, $this->executingSource(self::once()))->run($this->buildRequest());

        self::assertFalse($result->isCached());
        self::assertStringContainsString('cache-key', $lockName);
    }

    public function testItReturnsTheCachedResultSavedByAnotherProcessWhileWaitingForTheLock(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->method('buildKey')->willReturn('cache-key');
        $resultCache->expects(self::exactly(2))->method('load')->willReturnOnConsecutiveCalls(null, $this->cachePayload());
        $resultCache->expects(self::never())->method('save');

        $result = $this->buildLockRunner(
            $this->buildLock($lockManager),
            $this->executingSource(self::never()),
            $resultCache,
        )->run($this->buildRequest());

        self::assertTrue($result->isCached());
    }

    public function testItReleasesTheLockWhenTheReportExecutionThrows(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->expects(self::once())->method('unlock')->willReturn(true);

        $this->expectException(RuntimeException::class);

        $this->buildLockRunner($this->buildLock($lockManager), $this->failingSource())->run($this->buildRequest());
    }

    public function testItDoesNotCacheAResultWhenTheExecutionThrows(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $resultCache = $this->createMock(ResultCache::class);
        $resultCache->method('buildKey')->willReturn('cache-key');
        $resultCache->method('load')->willReturn(null);
        $resultCache->expects(self::never())->method('save');

        try {
            $this->buildLockRunner($this->buildLock($lockManager), $this->failingSource(), $resultCache)->run($this->buildRequest());
            self::fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // expected
        }
    }

    public function testItExecutesWithoutTheLockWhenTheLockCannotBeAcquiredInTime(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $lockManager->expects(self::never())->method('unlock');

        $result = $this->buildLockRunner($this->buildLock($lockManager), $this->executingSource(self::once()))
            ->run($this->buildRequest());

        self::assertFalse($result->isCached());
    }

    public function testItDoesNotLockWhenTheRequestBypassesTheCache(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::never())->method('lock');
        $lockManager->expects(self::never())->method('unlock');

        $this->buildLockRunner($this->buildLock($lockManager), $this->executingSource(self::once()))
            ->run($this->buildRequest(bypassCache: true));
    }

    public function testItExecutesWithoutTheLockWhenAcquiringTheLockThrows(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willThrowException(new RuntimeException('deadlock'));
        $lockManager->expects(self::never())->method('unlock');

        $result = $this->buildLockRunner($this->buildLock($lockManager), $this->executingSource(self::once()))
            ->run($this->buildRequest());

        self::assertFalse($result->isCached());
    }

    public function testItRethrowsTheExecutionErrorEvenWhenReleasingTheLockFails(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->method('unlock')->willThrowException(new LogicException('unlock failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->buildLockRunner($this->buildLock($lockManager), $this->failingSource())->run($this->buildRequest());
    }

    public function testItLocksANestedRunUnderItsOwnKeyWhileTheOuterLockIsHeld(): void
    {
        $locked = [];
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(static function (string $name) use (&$locked): bool {
            $locked[] = $name;

            return true;
        });
        $resultCacheLock = $this->buildLock($lockManager);
        $resultCache = $this->createStub(ResultCache::class);
        $resultCache->method('buildKey')->willReturnOnConsecutiveCalls('outer-key', 'inner-key');
        $resultCache->method('load')->willReturn(null);

        /** @var ArrayObject<string, ReportRunner> $holder */
        $holder = new ArrayObject();
        $nestedRequest = $this->buildRequest();
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $calls = 0;
        $dataSource->method('execute')->willReturnCallback(
            function () use ($holder, &$calls, $nestedRequest): RawResult {
                if (++$calls === 1) {
                    ($holder['runner'] ?? throw new LogicException('runner missing'))->run($nestedRequest);
                }

                return new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 1.0]], totalRowCount: 0);
            },
        );
        $holder['runner'] = $this->buildLockRunner($resultCacheLock, $dataSource, $resultCache);

        $holder['runner']->run($this->buildRequest());

        self::assertSame(
            [$resultCacheLock->getLockName('outer-key'), $resultCacheLock->getLockName('inner-key')],
            $locked,
        );
    }

    private function executingSource(InvocationOrder $expectedCalls): DataSourceInterface
    {
        $dataSource = $this->createMock(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->expects($expectedCalls)->method('execute')->willReturn(
            new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 100.0]], totalRowCount: 0),
        );

        return $dataSource;
    }

    private function failingSource(): DataSourceInterface
    {
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willThrowException(new RuntimeException('boom'));

        return $dataSource;
    }

    /**
     * @return array<string,mixed>
     */
    private function cachePayload(): array
    {
        return [
            'columns' => [], 'rows' => [], 'totals' => ['metrics' => []], 'totalRowCount' => 0,
            'range' => null, 'comparisonRange' => null, 'currencyCode' => 'USD', 'warnings' => [],
            'source' => 'mysql', 'generatedAt' => '2026-09-25T10:00:00+00:00', 'meta' => [],
        ];
    }

    private function buildLockRunner(
        ResultCacheLock $resultCacheLock,
        DataSourceInterface $dataSource,
        ?ResultCache $resultCache = null,
    ): ReportRunner {
        if ($resultCache === null) {
            $resultCache = $this->createStub(ResultCache::class);
            $resultCache->method('buildKey')->willReturn('cache-key');
            $resultCache->method('load')->willReturn(null);
        }

        return $this->buildCurrencyRunner(
            $this->buildResolvedQuery(['net_sales']),
            null,
            $dataSource,
            ['resultCache' => $resultCache, 'resultCacheLock' => $resultCacheLock],
        );
    }

    private function buildCurrencyDataSource(): DataSourceInterface
    {
        $dataSource = $this->createStub(DataSourceInterface::class);
        $dataSource->method('getCode')->willReturn('mysql');
        $dataSource->method('execute')->willReturn(
            new RawResult(rows: [], totals: ['metrics' => ['net_sales' => 100.0]], totalRowCount: 0)
        );

        return $dataSource;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function buildCurrencyRunner(
        ResolvedQuery $mainQuery,
        ?ResolvedQuery $comparisonQuery,
        DataSourceInterface $dataSource,
        array $overrides = [],
    ): ReportRunner {
        $queryPlanner = $this->createStub(QueryPlanner::class);
        $queryPlanner->method('plan')->willReturn(['main' => $mainQuery, 'comparison' => $comparisonQuery]);
        $sourceResolver = $this->createStub(SourceResolver::class);
        $sourceResolver->method('resolve')->willReturn($dataSource);

        return $this->buildRunner([...$overrides, 'queryPlanner' => $queryPlanner, 'sourceResolver' => $sourceResolver]);
    }

    private function buildLock(LockManagerInterface $lockManager): ResultCacheLock
    {
        $state = $this->createStub(StateInterface::class);
        $state->method('isEnabled')->willReturn(true);

        return new ResultCacheLock($lockManager, new ResultCacheWriteGuard(), $this->config, $state);
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
            $overrides['baseCurrencyChecker'] ?? $this->baseCurrencyChecker,
            $overrides['monetaryRequestDetector'] ?? $this->monetaryRequestDetector,
            $overrides['currencyProbe'] ?? $this->currencyProbe,
            $this->localeResolver,
            $overrides['aclContext'] ?? $this->aclContext,
            $overrides['resultCacheLock'] ?? $this->buildLock($this->createStub(LockManagerInterface::class)),
            $overrides['resultConfigFingerprint'] ?? $this->resultConfigFingerprint,
            $overrides['quoteRetention'] ?? $this->quoteRetention,
            $overrides['timeRelativeDatasets'] ?? [],
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
            scope: new ReportScope(1, 'Europe/Rome', 1, ['Europe/Rome']),
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
            fields: [
                Dataset::FIELD_CREATED_AT => new Field(Dataset::FIELD_CREATED_AT, 'Created at', FieldType::DATE),
                Dataset::FIELD_BASE_CURRENCY => new Field(
                    Dataset::FIELD_BASE_CURRENCY,
                    'Base currency',
                    FieldType::STRING,
                ),
            ],
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
