<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;

/**
 * Applies the UTC date range and store scope of a {@see ResolvedQuery} to a select.
 *
 * The date filter is a plain range on the created_at expression (no CONVERT_TZ in WHERE) so native
 * indexes stay usable; when the scope spans several timezones the widened range is narrowed with
 * an OR of exact per-timezone ranges.
 */
class DateScopeApplier
{
    public function __construct(
        private readonly FieldExpressionResolver $fieldExpressionResolver,
        private readonly StoreTimezoneMap $storeTimezoneMap,
    ) {
    }

    /**
     * @param Select $select
     * @param ResolvedQuery $query
     * @param AdapterInterface $connection
     */
    public function apply(Select $select, ResolvedQuery $query, AdapterInterface $connection): void
    {
        $dataset = $query->dataset;
        $storeExpression = $dataset->hasField(Dataset::FIELD_STORE_ID)
            ? $this->fieldExpressionResolver->resolve($dataset, Dataset::FIELD_STORE_ID, $connection)
            : null;

        if ($query->dateRange !== null && $dataset->hasField(Dataset::FIELD_CREATED_AT)) {
            $createdAt = $this->fieldExpressionResolver->resolve($dataset, Dataset::FIELD_CREATED_AT, $connection);
            $range = $this->storeTimezoneMap->toUtcRange($query->dateRange, $query->storeIds);
            $select->where($this->rangeCondition($createdAt, $range, $connection));

            if ($storeExpression !== null && $this->storeTimezoneMap->hasMultipleTimezones($query->storeIds)) {
                $exact = [];
                foreach ($this->storeTimezoneMap->toUtcRangesByTimezone($query->dateRange, $query->storeIds) as $group) {
                    $exact[] = '(' . $connection->quoteInto($storeExpression . ' IN (?)', $group['storeIds'])
                        . ' AND ' . $this->rangeCondition($createdAt, $group, $connection) . ')';
                }
                $select->where(implode(' OR ', $exact));
            }
        }

        if ($storeExpression !== null && $query->storeIds !== []) {
            $select->where($connection->quoteInto($storeExpression . ' IN (?)', $query->storeIds));
        }
    }

    /**
     * @param string $expression
     * @param array{from: string, to: string} $range
     * @param AdapterInterface $connection
     */
    private function rangeCondition(string $expression, array $range, AdapterInterface $connection): string
    {
        return $connection->quoteInto($expression . ' >= ?', $range['from'])
            . ' AND ' . $connection->quoteInto($expression . ' < ?', $range['to']);
    }
}
