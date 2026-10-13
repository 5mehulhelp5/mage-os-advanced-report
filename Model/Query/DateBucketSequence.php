<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateInterval;
use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Definition\Granularity;

/**
 * Generates the expected sequence of date bucket keys a {@see Granularity} produces over a
 * {@see ResolvedDateRange}, in the same string formats used by the data sources (hour
 * `Y-m-d H:00`, day `Y-m-d`, week = week start date `Y-m-d`, month `Y-m`, year `Y`).
 *
 * Used by {@see ComparisonMerger} to align week/month/year buckets by ordinal position, and
 * by the date bucket gap filler.
 */
class DateBucketSequence
{
    /**
     * @return string[]
     */
    public function generate(ResolvedDateRange $range, Granularity $granularity, int $firstDayOfWeek = 0): array
    {
        return match ($granularity) {
            Granularity::HOUR => $this->generateHours($range),
            Granularity::DAY => $this->generateDays($range),
            Granularity::WEEK => $this->generateWeeks($range, $firstDayOfWeek),
            Granularity::MONTH => $this->generateMonths($range),
            Granularity::YEAR => $this->generateYears($range),
        };
    }

    /**
     * @return string[]
     */
    private function generateHours(ResolvedDateRange $range): array
    {
        $keys = [];
        $cursor = $range->from;
        $end = $range->to->modify('+1 day');
        while ($cursor < $end) {
            $keys[] = $cursor->format('Y-m-d H:00');
            $cursor = $cursor->add(new DateInterval('PT1H'));
        }

        return $keys;
    }

    /**
     * @return string[]
     */
    private function generateDays(ResolvedDateRange $range): array
    {
        $keys = [];
        $cursor = $range->from;
        while ($cursor <= $range->to) {
            $keys[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $keys;
    }

    /**
     * @return string[]
     */
    private function generateWeeks(ResolvedDateRange $range, int $firstDayOfWeek): array
    {
        $keys = [];
        $cursor = $this->weekStart($range->from, $firstDayOfWeek);
        while ($cursor <= $range->to) {
            $keys[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+7 days');
        }

        return $keys;
    }

    /**
     * @return string[]
     */
    private function generateMonths(ResolvedDateRange $range): array
    {
        $keys = [];
        $cursor = $range->from->modify('first day of this month');
        $end = $range->to->modify('first day of this month');
        while ($cursor <= $end) {
            $keys[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('first day of next month');
        }

        return $keys;
    }

    /**
     * @return string[]
     */
    private function generateYears(ResolvedDateRange $range): array
    {
        $keys = [];
        for ($year = (int) $range->from->format('Y'); $year <= (int) $range->to->format('Y'); $year++) {
            $keys[] = (string) $year;
        }

        return $keys;
    }

    /**
     * Start of the week containing `$date`, aligned to `$firstDayOfWeek`
     * (0 = Sunday ... 6 = Saturday, matching `general/locale/firstday`).
     */
    private function weekStart(DateTimeImmutable $date, int $firstDayOfWeek): DateTimeImmutable
    {
        $dayOfWeek = (int) $date->format('w');
        $diff = ($dayOfWeek - $firstDayOfWeek + 7) % 7;

        return $date->modify(\sprintf('-%d days', $diff));
    }
}
