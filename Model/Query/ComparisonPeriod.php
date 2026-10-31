<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

/**
 * A comparison date range together with the {@see CompareMode} that produced it and the
 * whole number of days between its "from" and the current period's "from", so the
 * comparison merger can align rows across periods.
 */
final readonly class ComparisonPeriod
{
    public function __construct(
        public ResolvedDateRange $range,
        public CompareMode $mode,
        public int $offsetDays,
    ) {
    }
}
