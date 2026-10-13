<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * Whitelisted comparison operators available for filtering report fields.
 */
enum FilterOperator: string
{
    case EQ = 'eq';
    case NEQ = 'neq';
    case IN = 'in';
    case NIN = 'nin';
    case GTE = 'gte';
    case LTE = 'lte';
    case BETWEEN = 'between';
}
