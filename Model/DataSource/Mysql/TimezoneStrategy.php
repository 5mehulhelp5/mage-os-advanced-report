<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use InvalidArgumentException;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;

/**
 * Builds SQL expressions bucketing UTC datetimes into the stores' local time.
 *
 * Values are inlined (quoted timezone names, cast first day) because the expressions may be
 * repeated in SELECT and ORDER BY, and native prepares do not support repeated named binds.
 */
class TimezoneStrategy
{
    public const int HOUR_SLOT_MINUTES = 60;
    public const int QUARTER_HOUR_SLOT_MINUTES = 15;

    public function __construct(
        private readonly StoreTimezoneMap $timezoneMap,
        private readonly TimezoneSupportDetector $detector,
        private readonly ReportConnection $reportConnection,
    ) {
    }

    /**
     * Strategy name exposed for diagnostics.
     */
    public function getName(): string
    {
        return $this->detector->detect();
    }

    /**
     * @param int[] $storeIds
     */
    public function localDateTime(string $createdAt, string $storeIdExpr, array $storeIds): string
    {
        $timezones = $this->timezoneMap->getTimezonesForStores($storeIds);
        if (\count(array_unique($timezones)) <= 1) {
            return $this->convert($createdAt, (string) (reset($timezones) ?: 'UTC'));
        }

        $cases = '';
        foreach ($timezones as $storeId => $timezone) {
            $cases .= \sprintf(' WHEN %d THEN %s', $storeId, $this->convert($createdAt, $timezone));
        }

        return \sprintf(
            'CASE %s%s ELSE %s END',
            $storeIdExpr,
            $cases,
            $this->convert($createdAt, $this->timezoneMap->getDefaultTimezone())
        );
    }

    /**
     * @param string $bucket hour|day|week|month|year|hour_of_day|weekday
     * @param int[] $storeIds
     * @param int $firstDayOfWeek 0 (Sunday) to 6 (Saturday), used by the week bucket.
     */
    public function bucket(
        string $bucket,
        string $createdAt,
        string $storeIdExpr,
        array $storeIds,
        int $firstDayOfWeek,
    ): string {
        $x = $this->localDateTime($createdAt, $storeIdExpr, $storeIds);

        return match ($bucket) {
            'hour' => \sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00')", $x),
            'day' => \sprintf('DATE(%s)', $x),
            'week' => \sprintf(
                'DATE_SUB(DATE(%1$s), INTERVAL (DAYOFWEEK(%1$s) - 1 - %2$d + 7) %% 7 DAY)',
                $x,
                $firstDayOfWeek % 7
            ),
            'month' => \sprintf("DATE_FORMAT(%s, '%%Y-%%m')", $x),
            'year' => \sprintf('YEAR(%s)', $x),
            'hour_of_day' => \sprintf('HOUR(%s)', $x),
            'weekday' => \sprintf('(DAYOFWEEK(%s) - 1)', $x),
            default => throw new InvalidArgumentException(\sprintf('Unknown bucket "%s".', $bucket)),
        };
    }

    /**
     * Fallback grouping used when the database cannot convert named timezones: the UTC slot
     * (`Y-m-d H:i:00`) of the given size (60 or 15 minutes) the datetime falls in.
     */
    public function utcSlot(string $createdAt, int $slotMinutes): string
    {
        if ($slotMinutes === self::QUARTER_HOUR_SLOT_MINUTES) {
            return \sprintf(
                "CONCAT(DATE_FORMAT(%1\$s, '%%Y-%%m-%%d %%H:'), LPAD(FLOOR(MINUTE(%1\$s) / 15) * 15, 2, '0'), ':00')",
                $createdAt
            );
        }

        return \sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00:00')", $createdAt);
    }

    private function convert(string $createdAt, string $timezone): string
    {
        $connection = $this->reportConnection->get();

        return \sprintf("CONVERT_TZ(%s, 'UTC', %s)", $createdAt, $connection->quote($timezone));
    }
}
