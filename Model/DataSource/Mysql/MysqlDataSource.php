<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Label\DimensionLabelApplier;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\FilterCondition;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Query\SortOrder;
use Throwable;

/**
 * Executes resolved queries on the configured MySQL connection.
 *
 * Normal path: sorted/paginated select, totals select and row-count select. When the database
 * lacks timezone tables and the query groups by a date bucket, the UTC-hour fallback select is
 * fetched, re-aggregated in PHP and sorted, counted and paginated in PHP.
 *
 * Row shape: see {@see RawResult}. Limits are capped at the configured maximum number of rows.
 *
 * @phpstan-type Row array{dims: array<string,?string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>}
 */
class MysqlDataSource implements DataSourceInterface
{
    private const string CODE = 'mysql';

    public function __construct(
        private readonly ReportConnection $reportConnection,
        private readonly SelectBuilder $selectBuilder,
        private readonly Guardrails $guardrails,
        private readonly TimezoneStrategy $timezoneStrategy,
        private readonly UtcHourReaggregator $reaggregator,
        private readonly DerivedMetricCalculator $derivedMetricCalculator,
        private readonly DimensionExpressionBuilder $dimensionExpressionBuilder,
        private readonly DimensionLabelApplier $dimensionLabelApplier,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritDoc
     */
    public function supports(Dataset $dataset, ReportRequestInterface $request): bool
    {
        $metricCodes = $request->getMetrics();
        $fieldCodes = array_map(
            static fn (FilterCondition $filter): string => $filter->field,
            $request->getFilters()
        );
        foreach ($request->getDimensions() as $selection) {
            if (!isset($dataset->dimensions[$selection->code])) {
                return false;
            }
            $fieldCodes[] = $dataset->dimensions[$selection->code]->fieldCode;
        }
        foreach ($metricCodes as $code) {
            if (!isset($dataset->metrics[$code])) {
                return false;
            }
        }
        foreach ($this->derivedMetricCalculator->getRequiredAggregates($dataset, $metricCodes) as $code) {
            $metric = $dataset->metrics[$code];
            array_push($fieldCodes, ...array_filter([$metric->fieldCode, $metric->conditionFieldCode]));
        }
        foreach ($fieldCodes as $fieldCode) {
            if (!$dataset->hasField($fieldCode) || !$dataset->getField($fieldCode)->isMappedForSource(self::CODE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(ReportRequestInterface $request, ResolvedQuery $resolvedQuery): RawResult
    {
        $query = $this->capLimit($resolvedQuery);
        $strategy = $this->timezoneStrategy->getName();
        $stats = ['ms' => 0.0, 'count' => 0];

        if ($strategy === TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK
            && $this->selectBuilder->hasDateBucketDimension($query)
        ) {
            [$rows, $count, $warnings] = $this->executeFallback($query, $stats);
        } else {
            [$rows, $count, $warnings] = $this->executeNormal($query, $stats);
        }

        $totalsRow = $this->fetchRow($this->selectBuilder->buildTotals($query), $stats);
        $truncation = $this->guardrails->getTruncationWarning($count);
        if ($truncation !== null) {
            $warnings[] = (string) $truncation;
        }

        return new RawResult(
            $this->dimensionLabelApplier->apply($query, $rows),
            ['metrics' => $this->castMetrics($query, $totalsRow)],
            $count,
            $warnings,
            ['timezone_strategy' => $strategy, 'sql_time_ms' => $stats['ms'], 'sql_query_count' => $stats['count']]
        );
    }

    /**
     * @param ResolvedQuery $query
     * @param array{ms: float, count: int} $stats
     * @return array{0: array<int,Row>, 1: int, 2: string[]}
     */
    private function executeNormal(ResolvedQuery $query, array &$stats): array
    {
        $rows = array_map(
            fn (array $fetched): array => $this->toRow($query, $fetched),
            $this->fetchAll($this->selectBuilder->buildPage($query), $stats)
        );

        return [$rows, $this->resolveRowCount($query, \count($rows), $stats), []];
    }

    /**
     * Derives the total from an incomplete page (or skips counting when not wanted) instead of
     * running the row count query.
     *
     * @param ResolvedQuery $query
     * @param int $pageSize
     * @param array{ms: float, count: int} $stats
     */
    private function resolveRowCount(ResolvedQuery $query, int $pageSize, array &$stats): int
    {
        if (!$query->countRows) {
            return $pageSize;
        }
        if ($query->limit !== null && $pageSize < $query->limit && ($query->offset === 0 || $pageSize > 0)) {
            return $query->offset + $pageSize;
        }

        return (int) $this->fetchOne($this->selectBuilder->buildRowCount($query), $stats);
    }

    /**
     * @param ResolvedQuery $query
     * @param array{ms: float, count: int} $stats
     * @return array{0: array<int,Row>, 1: int, 2: string[]}
     */
    private function executeFallback(ResolvedQuery $query, array &$stats): array
    {
        $fallback = $this->selectBuilder->buildUtcHourFallback($query);
        $fetched = $this->fetchAll($fallback->select, $stats);
        $warnings = [];
        if (\count($fetched) > $fallback->rowCap) {
            array_pop($fetched);
            $warnings[] = (string) __(
                'The database has no timezone tables and the report has too many time slots: '
                . 'results were truncated. Narrow the date range or add filters.'
            );
        }

        $rows = array_map(fn (array $row): array => $this->toRow($query, $row), $fetched);
        $aggregated = $this->reaggregate($query, $fallback, $rows);
        $sorted = $this->sortRows($query, $aggregated['rows']);

        return [
            \array_slice($sorted, $query->offset, $query->limit),
            \count($sorted),
            [...$warnings, ...array_map(strval(...), $aggregated['warnings'])],
        ];
    }

    /**
     * @param ResolvedQuery $query
     * @param UtcHourFallbackSelect $fallback
     * @param array<int,Row> $rows
     * @return array{rows: array<int,Row>, warnings: string[]}
     */
    private function reaggregate(ResolvedQuery $query, UtcHourFallbackSelect $fallback, array $rows): array
    {
        $buckets = $this->bucketsByDimension($query, $fallback->replacedDimensions);
        $mainCode = (string) array_key_first($buckets);
        $mainBucket = (string) array_shift($buckets);
        foreach ($buckets as $code => $bucket) {
            foreach ($rows as $index => $row) {
                $single = $this->reaggregator->reaggregate(
                    [$row],
                    $code,
                    $bucket,
                    [],
                    $query->storeIds,
                    $query->scope->firstDayOfWeek,
                    $fallback->storeColumn
                );
                $rows[$index]['dims_raw'][$code] = $single['rows'][0]['dims_raw'][$code];
            }
        }

        return $this->reaggregator->reaggregate(
            $rows,
            $mainCode,
            $mainBucket,
            array_map($query->dataset->getMetric(...), $query->metrics),
            $query->storeIds,
            $query->scope->firstDayOfWeek,
            $fallback->storeColumn,
            $fallback->dropStoreColumn
        );
    }

    /**
     * @param ResolvedQuery $query
     * @param string[] $codes
     * @return array<string,string>
     */
    private function bucketsByDimension(ResolvedQuery $query, array $codes): array
    {
        $buckets = [];
        foreach ($query->dimensions as $selection) {
            if (\in_array($selection->code, $codes, true)) {
                $buckets[$selection->code] = (string) $this->dimensionExpressionBuilder->getBucket(
                    $query->dataset,
                    $selection
                );
            }
        }

        return $buckets;
    }

    /**
     * Sorts by the requested targets, then by dimensions ascending (tie-breakers), like the SQL path.
     *
     * @param ResolvedQuery $query
     * @param array<int,Row> $rows
     * @return array<int,Row>
     */
    private function sortRows(ResolvedQuery $query, array $rows): array
    {
        $dimensionCodes = array_map(static fn (DimensionSelection $s): string => $s->code, $query->dimensions);
        $sortMetrics = array_filter(
            array_map(static fn (SortOrder $sort): string => $sort->by, $query->sort),
            static fn (string $code): bool => isset($query->dataset->metrics[$code])
        );
        $computed = $this->derivedMetricCalculator->apply(
            $query->dataset,
            array_values(array_unique([...$query->metrics, ...$sortMetrics])),
            $rows,
            []
        )['rows'];

        $terms = [];
        foreach ($query->sort as $sort) {
            $terms[] = [$sort->by, strtolower($sort->direction) === 'desc' ? -1 : 1];
        }
        foreach ($dimensionCodes as $code) {
            $terms[] = [$code, 1];
        }

        $indexes = array_keys($rows);
        usort($indexes, function (int $left, int $right) use ($terms, $rows, $computed, $dimensionCodes): int {
            foreach ($terms as [$code, $direction]) {
                $isDimension = \in_array($code, $dimensionCodes, true);
                $a = $isDimension ? $rows[$left]['dims_raw'][$code] : $computed[$left]['metrics'][$code];
                $b = $isDimension ? $rows[$right]['dims_raw'][$code] : $computed[$right]['metrics'][$code];
                $result = $this->compareValues($a, $b) * $direction;
                if ($result !== 0) {
                    return $result;
                }
            }

            return 0;
        });

        return array_map(static fn (int $index): array => $rows[$index], $indexes);
    }

    /**
     * Ascending comparison with NULL first (as MySQL) and numeric strings compared as numbers.
     *
     * @param mixed $left
     * @param mixed $right
     */
    private function compareValues(mixed $left, mixed $right): int
    {
        if ($left === null || $right === null) {
            return ($left !== null) <=> ($right !== null);
        }
        if (is_numeric($left) && is_numeric($right)) {
            return $left <=> $right;
        }

        return strcmp((string) $left, (string) $right);
    }

    /**
     * Applies the configured maximum number of rows as the query limit.
     *
     * @param ResolvedQuery $query
     */
    private function capLimit(ResolvedQuery $query): ResolvedQuery
    {
        $max = $this->guardrails->getMaxRows();

        return new ResolvedQuery(
            $query->dataset,
            $query->metrics,
            $query->dimensions,
            $query->filters,
            $query->dateRange,
            $query->storeIds,
            $query->sort,
            $query->limit === null ? $max : min($query->limit, $max),
            $query->offset,
            $query->scope,
            $query->countRows
        );
    }

    /**
     * @param ResolvedQuery $query
     * @param array<string,mixed> $fetched
     * @return Row
     */
    private function toRow(ResolvedQuery $query, array $fetched): array
    {
        $dims = array_map(
            static fn (mixed $value): ?string => $value === null ? null : (string) $value,
            array_diff_key($fetched, array_flip($query->metrics))
        );

        return ['dims' => $dims, 'dims_raw' => $dims, 'metrics' => $this->castMetrics($query, $fetched)];
    }

    /**
     * @param ResolvedQuery $query
     * @param array<string,mixed> $fetched
     * @return array<string,int|float|null>
     */
    private function castMetrics(ResolvedQuery $query, array $fetched): array
    {
        $metrics = [];
        foreach ($query->metrics as $code) {
            $value = $fetched[$code] ?? null;
            $metrics[$code] = $value === null
                ? null
                : ($this->isIntegerMetric($query->dataset, $query->dataset->getMetric($code))
                    ? (int) round((float) $value)
                    : (float) $value);
        }

        return $metrics;
    }

    private function isIntegerMetric(Dataset $dataset, Metric $metric): bool
    {
        return match ($metric->aggregate) {
            AggregateFunction::COUNT, AggregateFunction::COUNT_DISTINCT => true,
            AggregateFunction::SUM, AggregateFunction::MIN, AggregateFunction::MAX => $metric->fieldCode !== null
                && $dataset->getField($metric->fieldCode)->type === FieldType::INTEGER,
            default => false,
        };
    }

    /**
     * @param Select $select
     * @param array{ms: float, count: int} $stats
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(Select $select, array &$stats): array
    {
        return $this->run(
            $select,
            $stats,
            static fn (AdapterInterface $c, string $sql, array $bind): array => $c->fetchAll($sql, $bind)
        );
    }

    /**
     * @param Select $select
     * @param array{ms: float, count: int} $stats
     * @return array<string,mixed>
     */
    private function fetchRow(Select $select, array &$stats): array
    {
        return $this->run(
            $select,
            $stats,
            static fn (AdapterInterface $c, string $sql, array $bind): array => $c->fetchRow($sql, $bind) ?: []
        );
    }

    /**
     * @param Select $select
     * @param array{ms: float, count: int} $stats
     */
    private function fetchOne(Select $select, array &$stats): mixed
    {
        return $this->run(
            $select,
            $stats,
            static fn (AdapterInterface $c, string $sql, array $bind): mixed => $c->fetchOne($sql, $bind)
        );
    }

    /**
     * Runs the select with the timeout applied, accumulating its duration and mapping driver errors.
     *
     * @param Select $select
     * @param array{ms: float, count: int} $stats
     * @param callable $statement
     * @throws Throwable
     */
    private function run(Select $select, array &$stats, callable $statement): mixed
    {
        $connection = $this->reportConnection->get();
        $started = hrtime(true);
        try {
            return $statement($connection, $this->guardrails->applyTimeout((string) $select), $select->getBind());
        } catch (Throwable $error) {
            throw $this->guardrails->mapException($error);
        } finally {
            $stats['ms'] += (hrtime(true) - $started) / 1e6;
            ++$stats['count'];
        }
    }
}
