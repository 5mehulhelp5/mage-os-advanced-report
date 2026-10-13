<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateInterval;

/**
 * A comparison date range together with the offset that maps one of its dates onto the
 * corresponding date of the current period it is being compared to
 * (`comparisonDate + offset === currentDate`), so the comparison merger can align rows
 * across periods.
 */
final readonly class ComparisonPeriod
{
    public function __construct(
        public ResolvedDateRange $range,
        public DateInterval $offset,
    ) {
    }
}
