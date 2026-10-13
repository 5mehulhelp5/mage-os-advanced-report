<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Stock;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Stock from `cataloginventory_stock_item` (default stock, simple-like products).
 */
class LegacyStockProvider extends AbstractStockProvider
{
    protected function applyBase(AdapterInterface $connection, Select $select): void
    {
        $select->from(
            ['si' => $connection->getTableName('cataloginventory_stock_item')],
            ['product_id' => 'si.product_id', 'qty' => 'si.qty', 'source_code' => new \Zend_Db_Expr("'default'")]
        )->join(
            ['e' => $connection->getTableName('catalog_product_entity')],
            'e.entity_id = si.product_id',
            ['sku' => 'e.sku']
        )->where('si.stock_id = ?', 1)
            ->where('e.type_id IN (?)', ['simple', 'virtual', 'downloadable']);
    }
}
