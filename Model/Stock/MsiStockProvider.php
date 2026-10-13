<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Stock;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Stock from `inventory_source_item` (table names only, no MSI class dependency).
 */
class MsiStockProvider extends AbstractStockProvider
{
    protected function applyBase(AdapterInterface $connection, Select $select): void
    {
        $select->from(
            ['si' => $connection->getTableName('inventory_source_item')],
            ['qty' => 'si.quantity', 'source_code' => 'si.source_code']
        )->join(
            ['e' => $connection->getTableName('catalog_product_entity')],
            'e.sku = si.sku',
            ['product_id' => 'e.entity_id', 'sku' => 'e.sku']
        );
    }
}
