<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * SQL aggregate functions available to aggregate metrics.
 */
enum AggregateFunction: string
{
    case SUM = 'sum';
    case COUNT = 'count';
    case COUNT_DISTINCT = 'count_distinct';
    case MIN = 'min';
    case MAX = 'max';
    case AVG = 'avg';
}
