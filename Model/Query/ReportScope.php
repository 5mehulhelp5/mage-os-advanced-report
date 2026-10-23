<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

/**
 * Reference store, timezone and first day of week a report is resolved in, derived from the
 * request's store ids by {@see ReportScopeResolver}.
 */
final readonly class ReportScope
{
    /**
     * @param int|null $referenceStoreId Null when the stores in scope span several timezones.
     * @param string $timezone Timezone relative date ranges are resolved in.
     * @param int $firstDayOfWeek 0 (Sunday) to 6 (Saturday).
     * @param string[] $timezones Distinct, sorted timezones of the stores in scope.
     */
    public function __construct(
        public ?int $referenceStoreId,
        public string $timezone,
        public int $firstDayOfWeek,
        public array $timezones,
    ) {
    }
}
