<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Stock;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\AdvancedReport\Model\DataSource\Mysql\BaseSelectProviderInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Delegates to the MSI provider when Magento_InventoryApi is enabled, otherwise to the legacy one.
 */
class StockSelectProvider implements BaseSelectProviderInterface
{
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly LegacyStockProvider $legacyProvider,
        private readonly MsiStockProvider $msiProvider
    ) {
    }

    public function getSelect(AdapterInterface $connection, Dataset $dataset): Select
    {
        $provider = $this->moduleManager->isEnabled('Magento_InventoryApi')
            ? $this->msiProvider
            : $this->legacyProvider;

        return $provider->getSelect($connection, $dataset);
    }
}
