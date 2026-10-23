<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Select;

/**
 * The select used when the database cannot convert named timezones, with what the caller needs
 * to re-aggregate it in PHP (see {@see UtcHourReaggregator}).
 */
final readonly class UtcHourFallbackSelect
{
    /**
     * @param Select $select Grouped by UTC hour (plus store id), without ORDER BY/OFFSET; LIMIT is `rowCap + 1`.
     * @param string[] $replacedDimensions Codes of the date dimensions replaced by the UTC hour column.
     * @param int $rowCap Safety cap: more than this many fetched rows means the result was truncated.
     * @param string $hourColumn Alias of the UTC hour column.
     * @param string|null $storeColumn Alias of the store id column, present when several timezones apply.
     * @param bool $dropStoreColumn Whether the store column is only a helper (not a requested dimension).
     * @param int $slotMinutes Size of the UTC slots the select is grouped by: 60 or 15.
     */
    public function __construct(
        public Select $select,
        public array $replacedDimensions,
        public int $rowCap,
        public string $hourColumn,
        public ?string $storeColumn,
        public bool $dropStoreColumn,
        public int $slotMinutes,
    ) {
    }
}
