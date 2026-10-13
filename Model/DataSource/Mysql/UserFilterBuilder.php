<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
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
}
