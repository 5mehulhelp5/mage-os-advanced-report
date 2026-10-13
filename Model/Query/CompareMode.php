<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

/**
 * How the requested period is compared against another period.
 */
enum CompareMode: string
{
    case NONE = 'none';
    case PREVIOUS_PERIOD = 'previous_period';
    case PREVIOUS_YEAR = 'previous_year';
}
