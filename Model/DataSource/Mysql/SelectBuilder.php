<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use InvalidArgumentException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;

/**
 * Translates a {@see ResolvedQuery} into a select on the configured connection.
 *
 * All user filter values are escaped inline through the adapter's `quoteInto` (see
 * {@see UserFilterBuilder}); no raw value is ever concatenated into the SQL.
 */
class SelectBuilder
{
    private const string UTC_HOUR_COLUMN = 'utc_hour';
    private const string STORE_COLUMN = 'store_id';
    private const int FALLBACK_ROW_CAP_FACTOR = 50;

    /**
     * @param array<string,BaseSelectProviderInterface> $baseSelectProviders Keyed by provider class name.
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ReportConnection $reportConnection,
        private readonly JoinRegistry $joinRegistry,
        private readonly FieldExpressionResolver $fieldExpressionResolver,
        private readonly AggregateExpressionBuilder $aggregateExpressionBuilder,
        private readonly DimensionExpressionBuilder $dimensionExpressionBuilder,
        private readonly ImplicitFilterPool $implicitFilterPool,
        private readonly UserFilterBuilder $userFilterBuilder,
        private readonly DateScopeApplier $dateScopeApplier,
        private readonly SortApplier $sortApplier,
        private readonly TimezoneStrategy $timezoneStrategy,
        private readonly StoreTimezoneMap $timezoneMap,
        private readonly Guardrails $guardrails,
        private readonly array $baseSelectProviders = [],
    ) {
    }

    /**
     * Select with joins, implicit and user filters, dimension columns/grouping and aggregate metrics.
     *
     * @param ResolvedQuery $query
     */
    public function build(ResolvedQuery $query): Select
    {
        return $this->buildSelect($query, false);
    }

    /**
     * {@see build()} plus ORDER BY (derived metrics sorted by their rendered formula, dimension
     * tie-breakers) and LIMIT/OFFSET.
     *
     * @param ResolvedQuery $query
     */
    public function buildPage(ResolvedQuery $query): Select
    {
        $select = $this->build($query);
        $this->sortApplier->apply($select, $query, $this->getConnection());

        return $select;
    }

    /**
     * Whether the query groups by a date bucket, hour-of-day or weekday dimension, i.e. whether
     * the UTC-hour fallback shape applies when the database lacks timezone tables.
     *
     * @param ResolvedQuery $query
     */
    public function hasDateBucketDimension(ResolvedQuery $query): bool
    {
        return $this->dateBucketDimensions($query) !== [];
    }

    /**
     * Select for databases without timezone tables: date dimensions are replaced by a `utc_hour`
     * column (plus `store_id` when several timezones apply); no ORDER BY/OFFSET, and LIMIT is
     * `max_rows * 50 + 1` so that more than `rowCap` rows signals truncation. Sorting, pagination
     * and re-aggregation happen in PHP.
     *
     * @param ResolvedQuery $query
     */
    public function buildUtcHourFallback(ResolvedQuery $query): UtcHourFallbackSelect
    {
        $select = $this->buildSelect($query, true);
        $rowCap = $this->guardrails->getMaxRows() * self::FALLBACK_ROW_CAP_FACTOR;
        $select->limit($rowCap + 1);
        $multiple = $this->timezoneMap->hasMultipleTimezones($query->storeIds);
        $storeIsDimension = \in_array(self::STORE_COLUMN, $this->codesOf($query->dimensions), true);

        return new UtcHourFallbackSelect(
            $select,
            $this->codesOf($this->dateBucketDimensions($query)),
            $rowCap,
            self::UTC_HOUR_COLUMN,
            $multiple ? self::STORE_COLUMN : null,
            $multiple && !$storeIsDimension,
            $this->slotMinutes($query)
        );
    }

    private function slotMinutes(ResolvedQuery $query): int
    {
        return $this->timezoneMap->requiresQuarterHourSlots($query->storeIds, $query->dateRange)
            ? TimezoneStrategy::QUARTER_HOUR_SLOT_MINUTES
            : TimezoneStrategy::HOUR_SLOT_MINUTES;
    }

    private function buildSelect(ResolvedQuery $query, bool $utcHourFallback): Select
    {
        $connection = $this->getConnection();
        $select = $this->buildFiltered($query, $this->collectFieldCodes($query), $connection);
        $this->applyColumns($select, $query, $connection, $utcHourFallback);

        return $select;
    }

    /**
     * FROM, joins for the given field codes, date/store scope, implicit and user filters.
     *
     * @param ResolvedQuery $query
     * @param string[] $fieldCodes
     * @param AdapterInterface $connection
     */
    private function buildFiltered(ResolvedQuery $query, array $fieldCodes, AdapterInterface $connection): Select
    {
        $dataset = $query->dataset;
        $select = $connection->select();
        $select->from([$this->baseAlias($dataset) => $this->baseSource($dataset, $connection)], []);
        foreach ($this->joinRegistry->resolve($dataset, $fieldCodes) as $join) {
            $select->joinLeft(
                [$join->alias => $this->resourceConnection->getTableName($join->table)],
                $join->condition,
                []
            );
        }
        $this->applyFilters($select, $query, $connection);

        return $select;
    }

    /**
     * Probe for the distinct order base currencies inside the exact scope of the query: same FROM,
     * date/store scope, implicit and user filters, selecting only `DISTINCT base_currency_code LIMIT 2`.
     * Only the joins needed by scope, filters and the currency field are added.
     *
     * @param ResolvedQuery $query
     */
    public function buildCurrencyProbe(ResolvedQuery $query): Select
    {
        $connection = $this->getConnection();
        $dataset = $query->dataset;
        $codes = $this->implicitFilterPool->getFieldCodes($dataset);
        foreach ([Dataset::FIELD_CREATED_AT, Dataset::FIELD_STORE_ID] as $reserved) {
            if ($dataset->hasField($reserved)) {
                $codes[] = $reserved;
            }
        }
        $codes[] = Dataset::FIELD_BASE_CURRENCY;
        foreach ($query->filters as $filter) {
            $codes[] = $filter->field;
        }

        $select = $this->buildFiltered($query, array_values(array_unique($codes)), $connection);
        $select->columns([Dataset::FIELD_BASE_CURRENCY => new Expression(
            $this->fieldExpressionResolver->resolve($dataset, Dataset::FIELD_BASE_CURRENCY, $connection)
        )]);

        return $select->distinct(true)->limit(2);
    }

    /**
     * Same select without dimensions: a single row of overall metric totals (no GROUP BY).
     *
     * @param ResolvedQuery $query
     */
    public function buildTotals(ResolvedQuery $query): Select
    {
        return $this->build(new ResolvedQuery(
            $query->dataset,
            $query->metrics,
            [],
            $query->filters,
            $query->dateRange,
            $query->storeIds,
            [],
            null,
            0,
            $query->scope,
            $query->countRows
        ));
    }

    /**
     * Number of result rows: `SELECT COUNT(*) FROM (grouped select)`.
     *
     * @param ResolvedQuery $query
     */
    public function buildRowCount(ResolvedQuery $query): Select
    {
        $connection = $this->getConnection();
        $select = $connection->select();
        $select->from(['grouped' => $this->build($query)], [])->columns(['COUNT(*)']);

        return $select;
    }

    private function getConnection(): AdapterInterface
    {
        return $this->reportConnection->get();
    }

    private function baseAlias(Dataset $dataset): string
    {
        return (string) ($dataset->baseAlias ?? 'main');
    }

    /**
     * Base table name, or the provider select (derived table) for provider based datasets.
     *
     * @param Dataset $dataset
     * @param AdapterInterface $connection
     */
    private function baseSource(Dataset $dataset, AdapterInterface $connection): string|Select
    {
        if ($dataset->baseProvider === null) {
            return $this->resourceConnection->getTableName((string) $dataset->baseTable);
        }
        $provider = $this->baseSelectProviders[$dataset->baseProvider] ?? throw new InvalidArgumentException(
            \sprintf('Unknown base select provider "%s".', $dataset->baseProvider)
        );

        return $provider->getSelect($connection, $dataset);
    }

    /**
     * @param Select $select
     * @param ResolvedQuery $query
     * @param AdapterInterface $connection
     */
    private function applyFilters(Select $select, ResolvedQuery $query, AdapterInterface $connection): void
    {
        $this->dateScopeApplier->apply($select, $query, $connection);
        foreach ($this->implicitFilterPool->getConditions($query->dataset, $connection) as $condition) {
            $select->where($condition);
        }
        foreach ($query->filters as $filter) {
            $expression = $this->fieldExpressionResolver->resolve($query->dataset, $filter->field, $connection);
            $select->where($this->userFilterBuilder->build($filter, $expression, $connection));
        }
    }

    /**
     * @param Select $select
     * @param ResolvedQuery $query
     * @param AdapterInterface $connection
     * @param bool $utcHourFallback
     */
    private function applyColumns(
        Select $select,
        ResolvedQuery $query,
        AdapterInterface $connection,
        bool $utcHourFallback
    ): void {
        $columns = $utcHourFallback ? $this->utcHourColumns($query, $connection) : [];
        $dimensionCodes = $this->codesOf($query->dimensions);
        foreach (array_diff(array_keys($columns), $dimensionCodes) as $alias) {
            $select->group($alias);
        }
        foreach ($query->dimensions as $selection) {
            if ($utcHourFallback && $this->dimensionExpressionBuilder->isDateBucket($query->dataset, $selection)) {
                continue;
            }
            $columns[$selection->code] = new Expression($this->dimensionExpressionBuilder->build(
                $query->dataset,
                $selection,
                $query->storeIds,
                $connection,
                $query->scope->firstDayOfWeek
            ));
            $select->group($selection->code);
        }
        foreach ($query->metrics as $code) {
            $columns[$code] = new Expression($this->aggregateExpressionBuilder->build(
                $query->dataset,
                $query->dataset->getMetric($code),
                $connection
            ));
        }
        $select->columns($columns);
    }

    /**
     * @param ResolvedQuery $query
     * @param AdapterInterface $connection
     * @return array<string,Expression>
     */
    private function utcHourColumns(ResolvedQuery $query, AdapterInterface $connection): array
    {
        if ($this->dateBucketDimensions($query) === []) {
            return [];
        }
        $dataset = $query->dataset;
        $columns = [self::UTC_HOUR_COLUMN => new Expression($this->timezoneStrategy->utcSlot(
            $this->fieldExpressionResolver->resolve($dataset, Dataset::FIELD_CREATED_AT, $connection),
            $this->slotMinutes($query)
        ))];
        if ($this->timezoneMap->hasMultipleTimezones($query->storeIds)) {
            $columns[self::STORE_COLUMN] = new Expression(
                $this->dimensionExpressionBuilder->storeIdExpression($dataset, $connection)
            );
        }

        return $columns;
    }

    /**
     * @param DimensionSelection[] $selections
     * @return string[]
     */
    private function codesOf(array $selections): array
    {
        return array_map(static fn (DimensionSelection $selection): string => $selection->code, $selections);
    }

    /**
     * @param ResolvedQuery $query
     * @return DimensionSelection[]
     */
    private function dateBucketDimensions(ResolvedQuery $query): array
    {
        return array_values(array_filter(
            $query->dimensions,
            fn (DimensionSelection $selection): bool => $this->dimensionExpressionBuilder->isDateBucket(
                $query->dataset,
                $selection
            )
        ));
    }

    /**
     * Field codes whose joins the query needs: reserved fields, implicit filters, dimensions,
     * metrics and user filters.
     *
     * @param ResolvedQuery $query
     * @return string[]
     */
    private function collectFieldCodes(ResolvedQuery $query): array
    {
        $dataset = $query->dataset;
        $codes = $this->implicitFilterPool->getFieldCodes($dataset);
        foreach ([Dataset::FIELD_CREATED_AT, Dataset::FIELD_STORE_ID] as $reserved) {
            if ($dataset->hasField($reserved)) {
                $codes[] = $reserved;
            }
        }
        foreach ($query->dimensions as $selection) {
            $codes[] = $dataset->getDimension($selection->code)->fieldCode;
        }
        foreach ($query->metrics as $code) {
            array_push($codes, ...$this->aggregateExpressionBuilder->getFieldCodes($dataset->getMetric($code)));
        }
        foreach ($query->filters as $filter) {
            $codes[] = $filter->field;
        }

        return array_values(array_unique($codes));
    }
}
