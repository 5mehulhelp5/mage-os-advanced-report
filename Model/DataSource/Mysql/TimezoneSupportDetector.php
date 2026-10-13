<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Cache\FrontendInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\Config;

/**
 * Detects (once, then cached) whether the report connection can convert named timezones.
 */
class TimezoneSupportDetector
{
    public const string STRATEGY_CONVERT_TZ = 'convert_tz';
    public const string STRATEGY_UTC_HOUR_FALLBACK = 'utc_hour_fallback';

    private const string CACHE_KEY = 'mageos_advancedreport_timezone_strategy';
    private const int CACHE_LIFETIME = 86400;

    private ?string $strategy = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param Config $config
     * @param FrontendInterface $cache
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly FrontendInterface $cache,
    ) {
    }

    /**
     * Strategy name: `convert_tz` or `utc_hour_fallback`.
     */
    public function detect(): string
    {
        if ($this->strategy !== null) {
            return $this->strategy;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached === self::STRATEGY_CONVERT_TZ || $cached === self::STRATEGY_UTC_HOUR_FALLBACK) {
            return $this->strategy = $cached;
        }

        $connection = $this->resourceConnection->getConnectionByName($this->config->getConnectionName());
        $supported = (int) $connection->fetchOne(
            "SELECT CONVERT_TZ('2000-01-01 00:00:00','UTC','Europe/Rome') IS NOT NULL"
        ) === 1;
        $strategy = $supported ? self::STRATEGY_CONVERT_TZ : self::STRATEGY_UTC_HOUR_FALLBACK;
        $this->cache->save($strategy, self::CACHE_KEY, [CacheType::CACHE_TAG], self::CACHE_LIFETIME);

        return $this->strategy = $strategy;
    }
}
