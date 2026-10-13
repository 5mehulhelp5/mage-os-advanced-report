<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;

/**
 * Concrete, inclusive local-date interval a {@see DateRange} resolves to, produced by
 * {@see DateRangeResolver}.
 */
final readonly class ResolvedDateRange
{
    /**
     * @param DateTimeImmutable $from Inclusive start of the range (local date, midnight).
     * @param DateTimeImmutable $to Inclusive end of the range (local date, midnight).
     * @param bool $includesToday Whether "today" falls within `[$from, $to]`.
     * @param int $days Number of calendar days in the range, inclusive of both ends.
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public bool $includesToday,
        public int $days,
    ) {
    }
}
