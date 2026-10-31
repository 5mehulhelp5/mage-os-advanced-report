<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;

/**
 * How far back Magento keeps quotes: carts last updated before `$cutoff` are (or soon will be)
 * deleted by the `sales_clean_quotes` cron.
 */
final readonly class QuoteRetentionWindow
{
    /**
     * @param int $days Effective quote lifetime in days (0 when quotes are deleted after the day they were updated).
     * @param DateTimeImmutable $cutoff Midnight of the oldest date whose carts are still guaranteed to exist.
     */
    public function __construct(
        public int $days,
        public DateTimeImmutable $cutoff,
    ) {
    }
}
