<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Timezone;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;

/**
 * Maps store ids to their (website scoped) timezone and converts local date ranges into UTC
 * datetime ranges so `created_at` filters can use native indexes.
 */
class StoreTimezoneMap
{
    private const string XML_PATH_TIMEZONE = 'general/locale/timezone';
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getTimezone(int $storeId): string
    {
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        $timezone = $this->scopeConfig->getValue(
            self::XML_PATH_TIMEZONE,
            ScopeInterface::SCOPE_WEBSITES,
            $websiteId
        );

        return \is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * @param int[] $storeIds Empty means all non-admin stores.
     * @return array<int,string>
     */
    public function getTimezonesForStores(array $storeIds): array
    {
        if ($storeIds === []) {
            $storeIds = array_map(
                static fn (StoreInterface $store): int => (int)$store->getId(),
                array_values($this->storeManager->getStores())
            );
        }

        $result = [];
        foreach ($storeIds as $storeId) {
            $result[(int)$storeId] = $this->getTimezone((int)$storeId);
        }

        return $result;
    }

    /**
     * @param int[] $storeIds
     */
    public function hasMultipleTimezones(array $storeIds): bool
    {
        return \count(array_unique($this->getTimezonesForStores($storeIds))) > 1;
    }

    /**
     * Half-open UTC range widened to cover every selected store's timezone.
     *
     * @param int[] $storeIds
     * @return array{from: string, to: string}
     */
    public function toUtcRange(ResolvedDateRange $range, array $storeIds): array
    {
        $from = null;
        $to = null;
        foreach ($this->toUtcRangesByTimezone($range, $storeIds) as $group) {
            $from = $from === null || $group['from'] < $from ? $group['from'] : $from;
            $to = $to === null || $group['to'] > $to ? $group['to'] : $to;
        }
        $utc = $this->convert($range, 'UTC');

        return ['from' => $from ?? $utc['from'], 'to' => $to ?? $utc['to']];
    }

    /**
     * @param int[] $storeIds
     * @return list<array{timezone: string, storeIds: int[], from: string, to: string}>
     */
    public function toUtcRangesByTimezone(ResolvedDateRange $range, array $storeIds): array
    {
        $groups = [];
        foreach ($this->getTimezonesForStores($storeIds) as $storeId => $timezone) {
            $groups[$timezone][] = $storeId;
        }

        $result = [];
        foreach ($groups as $timezone => $ids) {
            $result[] = ['timezone' => (string)$timezone, 'storeIds' => $ids] + $this->convert($range, (string)$timezone);
        }

        return $result;
    }

    /**
     * Whether any timezone in scope has, at some point of the range, a UTC offset that is not a
     * whole number of hours (so UTC hour slots would split local hours). A null range is checked
     * at the current instant.
     *
     * @param int[] $storeIds
     */
    public function requiresQuarterHourSlots(array $storeIds, ?ResolvedDateRange $range): bool
    {
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        $from = $range !== null ? $this->convertBoundary($range->from, $utc)->modify('-1 day') : $now;
        $to = $range !== null ? $this->convertBoundary($range->to, $utc)->modify('+2 days') : $now;
        foreach (array_unique($this->getTimezonesForStores($storeIds)) as $timezone) {
            $tz = new DateTimeZone($timezone);
            $offsets = [$tz->getOffset($from), $tz->getOffset($to)];
            foreach ($tz->getTransitions($from->getTimestamp(), $to->getTimestamp()) as $transition) {
                $offsets[] = $transition['offset'];
            }
            foreach ($offsets as $offset) {
                if ($offset % 3600 !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function convertBoundary(DateTimeImmutable $date, DateTimeZone $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00', $utc);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function convert(ResolvedDateRange $range, string $timezone): array
    {
        $tz = new DateTimeZone($timezone);
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($range->from->format('Y-m-d') . ' 00:00:00', $tz);
        $to = new DateTimeImmutable($range->to->format('Y-m-d') . ' 00:00:00', $tz);

        return [
            'from' => $from->setTimezone($utc)->format(self::DATETIME_FORMAT),
            'to' => $to->modify('+1 day')->setTimezone($utc)->format(self::DATETIME_FORMAT),
        ];
    }
}
