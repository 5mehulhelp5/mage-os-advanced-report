<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

/**
 * Fixed vocabulary of date range shortcuts a report request may select; `CUSTOM`
 * additionally requires {@see DateRange::$from}/{@see DateRange::$to}.
 */
enum DateRangeType: string
{
    case TODAY = 'today';
    case LAST_7_DAYS = 'last_7_days';
    case LAST_30_DAYS = 'last_30_days';
    case MONTH_TO_DATE = 'month_to_date';
    case LAST_MONTH = 'last_month';
    case YEAR_TO_DATE = 'year_to_date';
    case CUSTOM = 'custom';
}
