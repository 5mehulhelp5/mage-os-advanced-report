<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Model\Definition\FilterOperator;

/**
 * A single filter requested against a dataset field: which field, which whitelisted
 * operator, and its comparison value (scalar for eq/neq/gte/lte, array for in/nin/between).
 */
final readonly class FilterCondition
{
    /**
     * @param string $field
     * @param FilterOperator $operator
     * @param mixed $value
     */
    public function __construct(
        public string $field,
        public FilterOperator $operator,
        public mixed $value = null,
    ) {
    }
}
