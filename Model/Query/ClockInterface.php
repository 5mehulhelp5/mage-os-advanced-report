<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;

/**
 * Source of the current local date, injected so {@see DateRangeResolver} resolves relative
 * date ranges deterministically in tests.
 */
interface ClockInterface
{
    /**
     * The current local date in `$timezone`, as midnight of that date in PHP's default
     * timezone (the zone custom date ranges are parsed in), so instants compare consistently.
     */
    public function today(string $timezone): DateTimeImmutable;
}
