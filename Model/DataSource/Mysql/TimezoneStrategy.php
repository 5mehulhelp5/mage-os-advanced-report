<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;

/**
 * Builds SQL expressions bucketing UTC datetimes into the stores' local time.
 *
 * Values are inlined (quoted timezone names, cast first day) because the expressions may be
 * repeated in SELECT and ORDER BY, and native prepares do not support repeated named binds.
 */
class TimezoneStrategy
{
    private const string XML_PATH_FIRST_DAY = 'general/locale/firstday';

    public function __construct(
        private readonly StoreTimezoneMap $timezoneMap,
        private readonly TimezoneSupportDetector $detector,
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
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

        return \sprintf('CASE %s%s END', $storeIdExpr, $cases);
    }

    /**
     * @param string $bucket hour|day|week|month|year|hour_of_day|weekday
     * @param int[] $storeIds
     */
    public function bucket(string $bucket, string $createdAt, string $storeIdExpr, array $storeIds): string
    {
        $x = $this->localDateTime($createdAt, $storeIdExpr, $storeIds);

        return match ($bucket) {
            'hour' => \sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00')", $x),
            'day' => \sprintf('DATE(%s)', $x),
            'week' => \sprintf(
                'DATE_SUB(DATE(%1$s), INTERVAL (DAYOFWEEK(%1$s) - 1 - %2$d + 7) %% 7 DAY)',
                $x,
                $this->getFirstDay()
            ),
            'month' => \sprintf("DATE_FORMAT(%s, '%%Y-%%m')", $x),
            'year' => \sprintf('YEAR(%s)', $x),
            'hour_of_day' => \sprintf('HOUR(%s)', $x),
            'weekday' => \sprintf('(DAYOFWEEK(%s) - 1)', $x),
            default => throw new InvalidArgumentException(\sprintf('Unknown bucket "%s".', $bucket)),
        };
    }

    /**
     * Fallback grouping used when the database cannot convert named timezones.
     */
    public function utcHour(string $createdAt): string
    {
        return \sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00:00')", $createdAt);
    }

    private function convert(string $createdAt, string $timezone): string
    {
        $connection = $this->resourceConnection->getConnectionByName($this->config->getConnectionName());

        return \sprintf("CONVERT_TZ(%s, 'UTC', %s)", $createdAt, $connection->quote($timezone));
    }

    private function getFirstDay(): int
    {
        return ((int) $this->scopeConfig->getValue(self::XML_PATH_FIRST_DAY)) % 7;
    }
}
