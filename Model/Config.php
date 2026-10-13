<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Single entry point to `mageos_advancedreport` configuration values.
 */
class Config
{
    private const string XML_PATH_GENERAL_ENABLED = 'mageos_advancedreport/general/enabled';
    private const string XML_PATH_SALES_COUNTED_STATUSES = 'mageos_advancedreport/sales/counted_statuses';
    private const string XML_PATH_CATALOG_REPORT_ATTRIBUTES = 'mageos_advancedreport/catalog/report_attributes';
    private const string XML_PATH_CATALOG_DEAD_STOCK_DAYS = 'mageos_advancedreport/catalog/dead_stock_days';
    private const string XML_PATH_PERFORMANCE_CONNECTION_NAME = 'mageos_advancedreport/performance/connection_name';
    private const string XML_PATH_PERFORMANCE_MAX_ROWS = 'mageos_advancedreport/performance/max_rows';
    private const string XML_PATH_PERFORMANCE_QUERY_TIMEOUT_MS = 'mageos_advancedreport/performance/query_timeout_ms';
    private const string XML_PATH_PERFORMANCE_CACHE_TTL_OPEN_PERIOD
        = 'mageos_advancedreport/performance/cache_ttl_open_period';
    private const string XML_PATH_PERFORMANCE_CACHE_TTL_CLOSED_PERIOD
        = 'mageos_advancedreport/performance/cache_ttl_closed_period';
    private const string XML_PATH_GENERAL_LOCALE_FIRSTDAY = 'general/locale/firstday';

    private const int DEFAULT_DEAD_STOCK_DAYS = 90;
    private const string DEFAULT_CONNECTION_NAME = 'default';
    private const int DEFAULT_MAX_ROWS = 10000;
    private const int DEFAULT_QUERY_TIMEOUT_MS = 30000;
    private const int DEFAULT_CACHE_TTL_OPEN_PERIOD = 300;
    private const int DEFAULT_CACHE_TTL_CLOSED_PERIOD = 86400;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * Whether the module is enabled (menu, controllers, cron jobs).
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_GENERAL_ENABLED);
    }

    /**
     * Order statuses counted as sales, trimmed, without empty values.
     *
     * Empty means "all statuses except canceled" (interpreted by callers).
     *
     * @return array<int,string>
     */
    public function getCountedStatuses(): array
    {
        return $this->getTrimmedList(self::XML_PATH_SALES_COUNTED_STATUSES);
    }

    /**
     * Extra product attribute codes exposed as catalog report dimensions.
     *
     * @return array<int,string>
     */
    public function getReportAttributes(): array
    {
        return $this->getTrimmedList(self::XML_PATH_CATALOG_REPORT_ATTRIBUTES);
    }

    /**
     * Number of days without sales after which an in-stock product is dead stock.
     */
    public function getDeadStockDays(): int
    {
        return $this->getPositiveInt(self::XML_PATH_CATALOG_DEAD_STOCK_DAYS, self::DEFAULT_DEAD_STOCK_DAYS);
    }

    /**
     * Database connection name used to run report queries.
     */
    public function getConnectionName(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PERFORMANCE_CONNECTION_NAME));

        return $value !== '' ? $value : self::DEFAULT_CONNECTION_NAME;
    }

    /**
     * Hard cap on the number of rows a single report query may return.
     */
    public function getMaxRows(): int
    {
        return $this->getPositiveInt(self::XML_PATH_PERFORMANCE_MAX_ROWS, self::DEFAULT_MAX_ROWS);
    }

    /**
     * Maximum execution time (milliseconds) allowed for a report query.
     */
    public function getQueryTimeoutMs(): int
    {
        return $this->getPositiveInt(self::XML_PATH_PERFORMANCE_QUERY_TIMEOUT_MS, self::DEFAULT_QUERY_TIMEOUT_MS);
    }

    /**
     * Cache lifetime (seconds) for report data covering still-changing periods.
     */
    public function getCacheTtlOpenPeriod(): int
    {
        return $this->getPositiveInt(
            self::XML_PATH_PERFORMANCE_CACHE_TTL_OPEN_PERIOD,
            self::DEFAULT_CACHE_TTL_OPEN_PERIOD,
        );
    }

    /**
     * Cache lifetime (seconds) for report data covering closed (final) periods.
     */
    public function getCacheTtlClosedPeriod(): int
    {
        return $this->getPositiveInt(
            self::XML_PATH_PERFORMANCE_CACHE_TTL_CLOSED_PERIOD,
            self::DEFAULT_CACHE_TTL_CLOSED_PERIOD,
        );
    }

    /**
     * First day of the week (`general/locale/firstday`), store scope, default scope when `$storeId` is null.
     *
     * @param int|null $storeId
     */
    public function getFirstDayOfWeek(?int $storeId): int
    {
        $value = $storeId === null
            ? $this->scopeConfig->getValue(self::XML_PATH_GENERAL_LOCALE_FIRSTDAY)
            : $this->scopeConfig->getValue(
                self::XML_PATH_GENERAL_LOCALE_FIRSTDAY,
                ScopeInterface::SCOPE_STORE,
                $storeId,
            );

        return (int) $value;
    }

    /**
     * Reads a comma-separated config value as a trimmed list without empty values.
     *
     * @param string $path
     * @return array<int,string>
     */
    private function getTrimmedList(string $path): array
    {
        $raw = (string) $this->scopeConfig->getValue($path);
        if ($raw === '') {
            return [];
        }

        $values = array_map(trim(...), explode(',', $raw));

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /**
     * Reads a config value and casts it to a positive integer, falling back to `$default` otherwise.
     *
     * @param string $path
     * @param int $default
     */
    private function getPositiveInt(string $path, int $default): int
    {
        $value = $this->scopeConfig->getValue($path);
        if (!is_numeric($value)) {
            return $default;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $default;
    }
}
