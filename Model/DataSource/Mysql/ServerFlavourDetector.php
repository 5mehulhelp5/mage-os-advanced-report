<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Cache\FrontendInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\Config;

/**
 * Detects (once, then cached) whether the report connection talks to MySQL or MariaDB.
 */
class ServerFlavourDetector
{
    private const string CACHE_KEY = 'mageos_advancedreport_server_flavour';

    private ?ServerFlavour $flavour = null;

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
     * Server flavour derived from `SELECT VERSION()`.
     */
    public function detect(): ServerFlavour
    {
        if ($this->flavour !== null) {
            return $this->flavour;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        $flavour = \is_string($cached) ? ServerFlavour::tryFrom($cached) : null;
        if ($flavour === null) {
            $connection = $this->resourceConnection->getConnectionByName($this->config->getConnectionName());
            $version = (string) $connection->fetchOne('SELECT VERSION()');
            $flavour = stripos($version, 'MariaDB') !== false ? ServerFlavour::MariaDb : ServerFlavour::Mysql;
            $this->cache->save($flavour->value, self::CACHE_KEY, [CacheType::CACHE_TAG]);
        }

        return $this->flavour = $flavour;
    }
}
