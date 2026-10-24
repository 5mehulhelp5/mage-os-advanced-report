<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
use DateTimeInterface;
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\CurrencyProbeInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use MageOS\AdvancedReport\Model\Security\AclContext;

/**
 * The single entry point to run a report request end to end: plans it
 * ({@see QueryPlanner}), resolves the data source (or dispatches a preset query), uses the
 * result cache ({@see ResultCache}), executes the main and comparison queries, computes derived
 * metrics ({@see DerivedMetricCalculator}), fills empty date buckets ({@see
 * DateBucketPostProcessor}) for single date-bucket-dimension requests, merges the comparison
 * ({@see ComparisonMerger}) and collects warnings ({@see ReportWarningsCollector}).
 *
 * Not `final`: proxied (`ReportRunnerInterface\Proxy`) by preset queries that would otherwise
 * create a circular dependency with the {@see PresetQueryPool} (task 031).
 */
class ReportRunner implements ReportRunnerInterface
{
    /**
     * Margin metrics whose presence triggers computing `cost_coverage` for the low coverage
     * warning, even when not requested.
     *
     * @var string[]
     */
    private const array MARGIN_METRICS = ['gross_margin', 'margin_pct'];

    public function __construct(
        private readonly QueryPlanner $queryPlanner,
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly SourceResolver $sourceResolver,
        private readonly PresetQueryPool $presetQueryPool,
        private readonly DerivedMetricCalculator $derivedMetricCalculator,
        private readonly ComparisonMerger $comparisonMerger,
        private readonly ColumnMetadataBuilder $columnMetadataBuilder,
        private readonly ResultCache $resultCache,
        private readonly Config $config,
        private readonly DateRangeResolver $dateRangeResolver,
        private readonly DateBucketPostProcessor $dateBucketPostProcessor,
        private readonly ReportWarningsCollector $warningsCollector,
        private readonly BaseCurrencyChecker $baseCurrencyChecker,
        private readonly MonetaryRequestDetector $monetaryRequestDetector,
        private readonly CurrencyProbeInterface $currencyProbe,
        private readonly LocaleResolverInterface $localeResolver,
        private readonly AclContext $aclContext,
        private readonly ResultCacheLock $resultCacheLock,
    ) {
    }

    public function run(ReportRequestInterface $request): ReportResultInterface
    {
        $plan = $this->queryPlanner->plan($request);
        $dataset = $this->definitionRegistry->getDataset($request->getDataset());
        $execution = $this->resolveExecution($dataset, $request);

        $cacheKey = $this->buildCacheKey($request, $plan, $execution['sourceCode']);

        $computeAndSave = function () use ($request, $dataset, $plan, $execution, $cacheKey): ReportResultInterface {
            $result = $this->execute($request, $dataset, $plan, $execution);
            $this->resultCache->save($cacheKey, $this->toCachePayload($result), $this->isOpenPeriod($plan));

            return $result;
        };

        if ($request->isBypassCache()) {
            return $computeAndSave();
        }

        $loadCached = function () use ($cacheKey): ?ReportResultInterface {
            $cachedPayload = $this->resultCache->load($cacheKey);

            return $cachedPayload === null ? null : $this->hydrateFromCache($cachedPayload);
        };

        return $loadCached() ?? $this->resultCacheLock->run($cacheKey, $loadCached, $computeAndSave);
    }

    /**
     * @param array{main: ResolvedQuery, comparison: ?ResolvedQuery} $plan
     * @param array{sourceCode: string, isPreset: bool, execute: callable(ResolvedQuery): RawResult} $execution
     */
    private function execute(
        ReportRequestInterface $request,
        Dataset $dataset,
        array $plan,
        array $execution,
    ): ReportResultInterface {
        $mainQuery = $plan['main'];
        $comparisonQuery = $plan['comparison'];
        $requestedMetrics = $request->getMetrics();

        $needsCostCoverage = $this->needsCostCoverageWarning($dataset, $requestedMetrics);
        $calculationMetrics = $requestedMetrics;
        if ($needsCostCoverage) {
            $calculationMetrics = array_values(array_unique([...$requestedMetrics, 'cost_coverage']));
            $mainQuery = $this->withMetrics(
                $mainQuery,
                $this->derivedMetricCalculator->getRequiredAggregates($dataset, $calculationMetrics),
            );
        }

        $firstDayOfWeek = $mainQuery->scope->firstDayOfWeek;
        $isBucketRequest = $this->dateBucketPostProcessor->supports($request);
        $mainExecutionQuery = $isBucketRequest ? $this->withPaging($mainQuery, null, 0) : $mainQuery;

        $probeStatements = 0;
        $probedCurrencies = $execution['isPreset']
            ? []
            : $this->probeBaseCurrencies($request, $dataset, $plan, $probeStatements);

        $mainRaw = ($execution['execute'])($mainExecutionQuery);

        $rows = $mainRaw->rows;
        $totalRowCount = $mainRaw->totalRowCount;
        if ($isBucketRequest) {
            $processed = $this->dateBucketPostProcessor->process(
                $dataset,
                $request,
                $rows,
                $mainQuery->metrics,
                $mainQuery->dateRange,
                $firstDayOfWeek,
                $mainQuery->scope->timezones,
            );
            $rows = $processed['rows'];
            $totalRowCount = $processed['totalRowCount'];
        }

        $computed = $this->derivedMetricCalculator->apply($dataset, $calculationMetrics, $rows, $mainRaw->totals['metrics']);

        if ($isBucketRequest) {
            $sorted = $this->sortRows($computed['rows'], $request->getSort());
            $computed['rows'] = $this->paginate($sorted, $request->getLimit(), $request->getOffset());
        }

        $costCoverage = $needsCostCoverage ? $this->toFloat($computed['totals']['cost_coverage'] ?? null) : null;
        $warnings = array_values(array_unique([
            ...$mainRaw->warnings,
            ...$this->warningsCollector->collect($dataset, $request, $requestedMetrics, $costCoverage),
        ]));

        $rows = $computed['rows'];
        $totalsWithMetrics = ['metrics' => $computed['totals']];
        $comparisonRange = null;
        $meta = $mainRaw->meta;
        $meta['sql_query_count'] = $this->metaInt($mainRaw, 'sql_query_count') + $probeStatements;
        $meta['sql_time_ms'] = $this->metaFloat($mainRaw, 'sql_time_ms');

        if ($comparisonQuery !== null) {
            $comparisonExecutionQuery = $this->withPaging($comparisonQuery, $this->config->getMaxRows(), 0, false);
            $comparisonRaw = ($execution['execute'])($comparisonExecutionQuery);
            $comparisonComputed = $this->derivedMetricCalculator->apply(
                $dataset,
                $calculationMetrics,
                $comparisonRaw->rows,
                $comparisonRaw->totals['metrics'],
            );

            $alignment = $this->buildAlignment($request, $mainQuery, $firstDayOfWeek);
            $rows = $this->comparisonMerger->mergeRows($rows, $comparisonComputed['rows'], $requestedMetrics, $alignment);
            $totalsWithMetrics = $this->comparisonMerger->mergeTotals(
                $totalsWithMetrics,
                ['metrics' => $comparisonComputed['totals']],
                $requestedMetrics,
            );
            $comparisonRange = $comparisonQuery->dateRange;
            $meta['sql_query_count'] += $this->metaInt($comparisonRaw, 'sql_query_count');
            $meta['sql_time_ms'] += $this->metaFloat($comparisonRaw, 'sql_time_ms');
        }

        return new ReportResult(
            columns: $this->columnMetadataBuilder->build($dataset, $request),
            rows: $this->finalizeRows($rows, $requestedMetrics),
            totals: $this->finalizeTotals($totalsWithMetrics, $requestedMetrics),
            totalRowCount: $totalRowCount,
            range: $mainQuery->dateRange,
            comparisonRange: $comparisonRange,
            currencyCode: \count($probedCurrencies) === 1
                ? $probedCurrencies[0]
                : $this->baseCurrencyChecker->resolveCurrencyCode($dataset, $request->getStoreIds()),
            warnings: $warnings,
            source: $execution['sourceCode'],
            generatedAt: new DateTimeImmutable(),
            cached: false,
            meta: $meta,
        );
    }

    private function metaInt(RawResult $raw, string $key): int
    {
        return (int) ($raw->meta[$key] ?? 0);
    }

    private function metaFloat(RawResult $raw, string $key): float
    {
        return (float) ($raw->meta[$key] ?? 0.0);
    }

    /**
     * Distinct base currencies of the orders in the main and comparison periods of a monetary
     * request; empty when the probe does not apply.
     *
     * @param array{main: ResolvedQuery, comparison: ?ResolvedQuery} $plan
     * @param int $statements Receives the number of SQL statements run by the probe
     * @throws InvalidRequestException When the periods mix base currencies
     * @return string[]
     */
    private function probeBaseCurrencies(
        ReportRequestInterface $request,
        Dataset $dataset,
        array $plan,
        int &$statements,
    ): array {
        if (!$dataset->hasField(Dataset::FIELD_BASE_CURRENCY) || !$this->monetaryRequestDetector->isMonetary($dataset, $request)) {
            return [];
        }

        $statementsPerProbe = $dataset->hasField(Dataset::FIELD_CREATED_AT) ? 1 : 0;
        $currencies = $this->currencyProbe->getBaseCurrencies($plan['main']);
        $statements += $statementsPerProbe;
        if ($plan['comparison'] !== null) {
            $currencies = [...$currencies, ...$this->currencyProbe->getBaseCurrencies($plan['comparison'])];
            $statements += $statementsPerProbe;
        }
        $currencies = array_values(array_unique($currencies));
        sort($currencies);

        if (\count($currencies) > 1) {
            throw new InvalidRequestException(new Phrase(
                'The orders in the report period use different base currencies (%1): amounts cannot be added without conversion.',
                [implode(', ', $currencies)],
            ));
        }

        return $currencies;
    }

    /**
     * @return array{sourceCode: string, isPreset: bool, execute: callable(ResolvedQuery): RawResult}
     */
    private function resolveExecution(Dataset $dataset, ReportRequestInterface $request): array
    {
        $presetCode = $request->getPreset();
        if ($presetCode !== null) {
            $preset = $this->definitionRegistry->getPreset($presetCode);
            if ($preset->presetQuery !== null) {
                $presetQuery = $this->presetQueryPool->get($preset->presetQuery);

                return [
                    'sourceCode' => $preset->code,
                    'isPreset' => true,
                    'execute' => static fn (ResolvedQuery $query): RawResult => $presetQuery->execute($request, $query),
                ];
            }
        }

        $source = $this->sourceResolver->resolve($dataset, $request);

        return [
            'sourceCode' => $source->getCode(),
            'isPreset' => false,
            'execute' => static fn (ResolvedQuery $query): RawResult => $source->execute($request, $query),
        ];
    }

    /**
     * @param array{main: ResolvedQuery, comparison: ?ResolvedQuery} $plan
     */
    private function buildCacheKey(ReportRequestInterface $request, array $plan, string $sourceCode): string
    {
        return $this->resultCache->buildKey(
            $request,
            $sourceCode,
            $this->definitionRegistry->getConfigVersion(),
            $this->config->getCountedStatuses(),
            [
                'main' => $this->rangeToDateArray($plan['main']->dateRange),
                'comparison' => $this->rangeToDateArray($plan['comparison']?->dateRange),
            ],
            $this->localeResolver->getLocale(),
            $this->aclContext->getFingerprint(),
            $this->buildScopeContext($request, $plan['main']->scope),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildScopeContext(ReportRequestInterface $request, ReportScope $scope): array
    {
        return [
            'timezone' => $scope->timezone,
            'timezones' => $scope->timezones,
            'firstDayOfWeek' => $scope->firstDayOfWeek,
            'baseCurrencies' => $this->baseCurrencyChecker->getCurrencyCodes($request->getStoreIds()),
        ];
    }

    /**
     * @param array{main: ResolvedQuery, comparison: ?ResolvedQuery} $plan
     */
    private function isOpenPeriod(array $plan): bool
    {
        return $this->rangeIncludesToday($plan['main']->dateRange)
            || $this->rangeIncludesToday($plan['comparison']?->dateRange);
    }

    private function rangeIncludesToday(?ResolvedDateRange $range): bool
    {
        return $range !== null && $range->includesToday;
    }

    /**
     * @param string[] $requestedMetrics
     */
    private function needsCostCoverageWarning(Dataset $dataset, array $requestedMetrics): bool
    {
        return isset($dataset->metrics['cost_coverage'])
            && array_intersect(self::MARGIN_METRICS, $requestedMetrics) !== [];
    }

    private function buildAlignment(
        ReportRequestInterface $request,
        ResolvedQuery $mainQuery,
        int $firstDayOfWeek,
    ): ?DateBucketAlignment {
        $dimensions = $request->getDimensions();
        if (\count($dimensions) !== 1 || $mainQuery->dateRange === null) {
            return null;
        }

        $selection = $dimensions[0];
        if ($selection->granularity === null) {
            return null;
        }

        $comparisonPeriod = match ($request->getCompareTo()) {
            CompareMode::PREVIOUS_PERIOD => $this->dateRangeResolver->previousPeriod($mainQuery->dateRange, $mainQuery->scope),
            CompareMode::PREVIOUS_YEAR => $this->dateRangeResolver->previousYear($mainQuery->dateRange, $mainQuery->scope),
            CompareMode::NONE => null,
        };

        if ($comparisonPeriod === null) {
            return null;
        }

        return new DateBucketAlignment(
            $selection->code,
            $selection->granularity,
            $mainQuery->dateRange,
            $comparisonPeriod,
            $firstDayOfWeek,
        );
    }

    /**
     * @param string[] $metrics
     */
    private function withMetrics(ResolvedQuery $query, array $metrics): ResolvedQuery
    {
        return new ResolvedQuery(
            dataset: $query->dataset,
            metrics: $metrics,
            dimensions: $query->dimensions,
            filters: $query->filters,
            dateRange: $query->dateRange,
            storeIds: $query->storeIds,
            sort: $query->sort,
            limit: $query->limit,
            offset: $query->offset,
            scope: $query->scope,
            countRows: $query->countRows,
        );
    }

    private function withPaging(ResolvedQuery $query, ?int $limit, int $offset, ?bool $countRows = null): ResolvedQuery
    {
        return new ResolvedQuery(
            dataset: $query->dataset,
            metrics: $query->metrics,
            dimensions: $query->dimensions,
            filters: $query->filters,
            dateRange: $query->dateRange,
            storeIds: $query->storeIds,
            sort: $query->sort,
            limit: $limit,
            offset: $offset,
            scope: $query->scope,
            countRows: $countRows ?? $query->countRows,
        );
    }

    /**
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @param SortOrder[] $sortOrders
     * @return array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>
     */
    private function sortRows(array $rows, array $sortOrders): array
    {
        if ($sortOrders === []) {
            return $rows;
        }

        usort($rows, function (array $left, array $right) use ($sortOrders): int {
            foreach ($sortOrders as $sortOrder) {
                $comparison = $this->compareValues(
                    $left['metrics'][$sortOrder->by] ?? $left['dims_raw'][$sortOrder->by] ?? null,
                    $right['metrics'][$sortOrder->by] ?? $right['dims_raw'][$sortOrder->by] ?? null,
                );
                if ($comparison !== 0) {
                    return $sortOrder->direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    private function compareValues(mixed $left, mixed $right): int
    {
        if ($left === $right) {
            return 0;
        }
        if ($left === null) {
            return -1;
        }
        if ($right === null) {
            return 1;
        }

        return $left <=> $right;
    }

    /**
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @return array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>
     */
    private function paginate(array $rows, ?int $limit, int $offset): array
    {
        return \array_slice($rows, $offset, $limit);
    }

    /**
     * Drops helper metrics not in `$metricCodes` (e.g. `cost_coverage`) from rows/totals.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $metricCodes
     * @return array<int,array<string,mixed>>
     */
    private function finalizeRows(array $rows, array $metricCodes): array
    {
        return array_map(function (array $row) use ($metricCodes): array {
            $finalized = [
                'dims' => $row['dims'],
                'dims_raw' => $row['dims_raw'],
                'metrics' => $this->pick($row['metrics'], $metricCodes),
            ];
            if (\array_key_exists('comparison', $row)) {
                $finalized['comparison'] = $this->pick($row['comparison'], $metricCodes);
            }

            return $finalized;
        }, $rows);
    }

    /**
     * @param array<string,mixed> $totals
     * @param string[] $metricCodes
     * @return array<string,mixed>
     */
    private function finalizeTotals(array $totals, array $metricCodes): array
    {
        $finalized = ['metrics' => $this->pick($totals['metrics'], $metricCodes)];
        if (\array_key_exists('comparison', $totals)) {
            $finalized['comparison'] = $this->pick($totals['comparison'], $metricCodes);
        }

        return $finalized;
    }

    /**
     * @param array<string,mixed> $values
     * @param string[] $codes
     * @return array<string,mixed>
     */
    private function pick(array $values, array $codes): array
    {
        $picked = [];
        foreach ($codes as $code) {
            $picked[$code] = $values[$code] ?? null;
        }

        return $picked;
    }

    private function toFloat(int|float|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function rangeToDateArray(?ResolvedDateRange $range): ?array
    {
        return $range === null ? null : ['from' => $range->from->format('Y-m-d'), 'to' => $range->to->format('Y-m-d')];
    }

    /**
     * @return array<string,mixed>
     */
    private function toCachePayload(ReportResultInterface $result): array
    {
        return [
            'columns' => $result->getColumns(),
            'rows' => $result->getRows(),
            'totals' => $result->getTotals(),
            'totalRowCount' => $result->getTotalRowCount(),
            'range' => $this->rangeToCacheArray($result->getRange()),
            'comparisonRange' => $this->rangeToCacheArray($result->getComparisonRange()),
            'currencyCode' => $result->getCurrencyCode(),
            'warnings' => $result->getWarnings(),
            'source' => $result->getSource(),
            'generatedAt' => $result->getGeneratedAt()->format(DateTimeInterface::ATOM),
            'meta' => $result->getMeta(),
        ];
    }

    /**
     * @return array{from: string, to: string, includesToday: bool, days: int}|null
     */
    private function rangeToCacheArray(?ResolvedDateRange $range): ?array
    {
        return $range === null ? null : [
            'from' => $range->from->format('Y-m-d'),
            'to' => $range->to->format('Y-m-d'),
            'includesToday' => $range->includesToday,
            'days' => $range->days,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hydrateFromCache(array $payload): ReportResultInterface
    {
        return new ReportResult(
            columns: $payload['columns'],
            rows: $payload['rows'],
            totals: $payload['totals'],
            totalRowCount: $payload['totalRowCount'],
            range: $this->rangeFromCacheArray($payload['range']),
            comparisonRange: $this->rangeFromCacheArray($payload['comparisonRange']),
            currencyCode: $payload['currencyCode'],
            warnings: $payload['warnings'],
            source: $payload['source'],
            generatedAt: new DateTimeImmutable($payload['generatedAt']),
            cached: true,
            meta: $this->zeroSqlMeta($payload['meta']),
        );
    }

    /**
     * A cache hit runs no SQL: the cold numbers move to the `cached_` keys.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function zeroSqlMeta(array $meta): array
    {
        $meta['cached_sql_query_count'] = (int) ($meta['sql_query_count'] ?? 0);
        $meta['cached_sql_time_ms'] = (float) ($meta['sql_time_ms'] ?? 0.0);
        $meta['sql_query_count'] = 0;
        $meta['sql_time_ms'] = 0.0;

        return $meta;
    }

    /**
     * @param array{from: string, to: string, includesToday: bool, days: int}|null $data
     */
    private function rangeFromCacheArray(?array $data): ?ResolvedDateRange
    {
        return $data === null ? null : new ResolvedDateRange(
            new DateTimeImmutable($data['from']),
            new DateTimeImmutable($data['to']),
            $data['includesToday'],
            $data['days'],
        );
    }
}
