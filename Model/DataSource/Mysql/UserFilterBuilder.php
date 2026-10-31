<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Join;
use MageOS\AdvancedReport\Model\Query\FilterCondition;

/**
 * Renders user filters as SQL conditions.
 *
 * Values are always escaped through the adapter's `quoteInto` (inlined, never concatenated raw).
 */
class UserFilterBuilder
{
    /**
     * Condition for the filter applied on the given field expression.
     *
     * @param FilterCondition $filter
     * @param string $expression
     * @param AdapterInterface $connection
     */
    public function build(FilterCondition $filter, string $expression, AdapterInterface $connection): string
    {
        $value = $filter->value;
        if ($filter->operator === FilterOperator::BETWEEN) {
            [$from, $to] = array_values((array) $value);

            return $connection->quoteInto($expression . ' BETWEEN ?', $from)
                . $connection->quoteInto(' AND ?', $to);
        }

        $template = match ($filter->operator) {
            FilterOperator::EQ => '%s = ?',
            FilterOperator::NEQ => '%s <> ?',
            FilterOperator::IN => '%s IN (?)',
            FilterOperator::NIN => '%s NOT IN (?)',
            FilterOperator::GTE => '%s >= ?',
            FilterOperator::LTE => '%s <= ?',
        };

        return $connection->quoteInto(\sprintf($template, $expression), $value);
    }

    /**
     * EXISTS / NOT EXISTS subquery over a many-cardinality join: the join condition correlates the
     * subquery with the base row. `neq` / `nin` become NOT EXISTS of the positive comparison, so base
     * rows without any joined row are kept.
     *
     * @param FilterCondition $filter
     * @param string $expression
     * @param Join $join
     * @param string $table Resolved (prefixed) name of the join table.
     * @param AdapterInterface $connection
     */
    public function buildExists(
        FilterCondition $filter,
        string $expression,
        Join $join,
        string $table,
        AdapterInterface $connection
    ): string {
        $negated = \in_array($filter->operator, [FilterOperator::NEQ, FilterOperator::NIN], true);
        $positive = new FilterCondition($filter->field, match ($filter->operator) {
            FilterOperator::NEQ => FilterOperator::EQ,
            FilterOperator::NIN => FilterOperator::IN,
            default => $filter->operator,
        }, $filter->value);
        $subquery = $connection->select()
            ->from([$join->alias => $table], [new Expression('1')])
            ->where($join->condition)
            ->where($this->build($positive, $expression, $connection));

        return ($negated ? 'NOT ' : '') . 'EXISTS (' . $subquery . ')';
    }
}
