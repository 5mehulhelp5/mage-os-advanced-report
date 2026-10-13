<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Model\Definition\Granularity;

/**
 * Describes how to align a date bucket dimension's raw values between the current period and
 * its {@see ComparisonPeriod} so {@see ComparisonMerger} can match rows across periods.
 */
final readonly class DateBucketAlignment
{
    /**
     * @param string $dimensionCode `dims_raw` key holding the date bucket value.
     * @param Granularity $granularity
     * @param ResolvedDateRange $currentRange
     * @param ComparisonPeriod $comparisonPeriod
     * @param int $firstDayOfWeek Only meaningful for {@see Granularity::WEEK}.
     */
    public function __construct(
        public string $dimensionCode,
        public Granularity $granularity,
        public ResolvedDateRange $currentRange,
        public ComparisonPeriod $comparisonPeriod,
        public int $firstDayOfWeek = 0,
    ) {
    }
}
