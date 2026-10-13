<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

/**
 * Requested reporting period: either a named shortcut or a custom `Y-m-d` range.
 */
final readonly class DateRange
{
    /**
     * @param DateRangeType $type
     * @param string|null $from `Y-m-d`, set only when $type is {@see DateRangeType::CUSTOM}.
     * @param string|null $to `Y-m-d`, set only when $type is {@see DateRangeType::CUSTOM}.
     */
    public function __construct(
        public DateRangeType $type,
        public ?string $from = null,
        public ?string $to = null,
    ) {
    }
}
