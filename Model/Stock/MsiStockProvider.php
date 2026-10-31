<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Stock;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Stock from `inventory_source_item` of enabled sources (table names only, no MSI class dependency).
 *
 * Source items with status 0 (out of stock at the source) still count their physical quantity.
 */
class MsiStockProvider extends AbstractStockProvider
{
    protected function applyBase(AdapterInterface $connection, Select $select): void
    {
        $select->from(
            ['si' => $this->reportConnection->getTableName('inventory_source_item')],
            ['qty' => 'si.quantity', 'source_code' => 'si.source_code']
        )->join(
            ['src' => $this->reportConnection->getTableName('inventory_source')],
            'src.source_code = si.source_code AND src.enabled = 1',
            []
        )->join(
            ['e' => $this->reportConnection->getTableName('catalog_product_entity')],
            'e.sku = si.sku',
            ['product_id' => 'e.entity_id', 'sku' => 'e.sku']
        );
    }
}
