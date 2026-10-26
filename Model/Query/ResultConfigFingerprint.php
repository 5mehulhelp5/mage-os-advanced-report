<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AdvancedReport\Model\Config;

/**
 * Snapshot of every setting that changes report results (not just their presentation), so it
 * can be part of the result cache key: a change made outside the admin config save then
 * produces a new key instead of serving a stale entry until the TTL.
 */
class ResultConfigFingerprint
{
    private const string XML_PATH_MIN_QTY = 'cataloginventory/item_options/min_qty';

    public function __construct(
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function get(): array
    {
        $reportAttributes = $this->config->getReportAttributes();
        sort($reportAttributes);

        return [
            'reportAttributes' => $reportAttributes,
            'deadStockDays' => $this->config->getDeadStockDays(),
            'maxRows' => $this->config->getMaxRows(),
            'connectionName' => $this->config->getConnectionName(),
            'minQty' => (string) (float) $this->scopeConfig->getValue(self::XML_PATH_MIN_QTY),
        ];
    }
}
