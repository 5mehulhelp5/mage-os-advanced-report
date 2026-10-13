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
     * Current local date (midnight, no time component) in the report scope's timezone.
     */
    public function today(): DateTimeImmutable;
}
