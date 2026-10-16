<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Stock;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\DataSource\Mysql\BaseSelectProviderInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * SKU x stock grain with stock index quantity, pending reservations and salable quantity.
 *
 * Set-based equivalent of core `GetProductSalableQty` (table names only, no MSI class dependency):
 * salable_qty = is_salable ? index quantity + SUM(reservations) - effective min qty : 0.
 * There is no manage-stock branch and no clamping, so the value can be negative, exactly like core.
 * Product types without source item management (excludedProductTypes) are left out.
 * The default stock (id 1) reads the `inventory_stock_1` view (website 0); other stocks read the lazily
 * created `inventory_stock_<id>` index tables, skipped while missing.
 * `reserved_qty` is positive when quantity is reserved.
 */
class SalableStockSelectProvider implements BaseSelectProviderInterface
{
    private const int DEFAULT_STOCK_ID = 1;
    private const string XML_PATH_MIN_QTY = 'cataloginventory/item_options/min_qty';

    /**
     * @param string[] $excludedProductTypes
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly array $excludedProductTypes = []
    ) {
    }

    public function getSelect(AdapterInterface $connection, Dataset $dataset): Select
    {
        $indexSelects = $this->getIndexSelects($connection);
        if ($indexSelects === []) {
            return $this->getEmptySelect($connection);
        }

        $index = $connection->select()->union($indexSelects, Select::SQL_UNION_ALL);
        $minQty = (string) (float) $this->scopeConfig->getValue(self::XML_PATH_MIN_QTY);
        $reservation = 'COALESCE(r.reservation_qty, 0)';
        $effectiveMinQty = 'CASE WHEN si.item_id IS NULL OR si.use_config_min_qty = 1 THEN '
            . $connection->quote($minQty) . ' ELSE si.min_qty END';

        $select = $connection->select()
            ->from(['s' => $index], ['stock_id' => 's.stock_id', 'stock_qty' => 's.stock_qty'])
            ->join(
                ['e' => $connection->getTableName('catalog_product_entity')],
                'e.sku = s.sku',
                ['sku' => 'e.sku', 'product_id' => 'e.entity_id']
            )
            ->joinLeft(
                ['r' => $this->getReservationSelect($connection)],
                'r.stock_id = s.stock_id AND r.sku = s.sku',
                []
            )
            ->joinLeft(
                ['si' => $connection->getTableName('cataloginventory_stock_item')],
                'si.product_id = e.entity_id AND si.website_id = 0',
                []
            )
            ->columns([
                'reserved_qty' => new \Zend_Db_Expr('-' . $reservation),
                'salable_qty' => new \Zend_Db_Expr(
                    'CASE WHEN s.is_salable = 1 THEN s.stock_qty + ' . $reservation
                    . ' - (' . $effectiveMinQty . ') ELSE 0 END'
                ),
            ]);
        if ($this->excludedProductTypes !== []) {
            $select->where('e.type_id NOT IN (?)', $this->excludedProductTypes);
        }

        return $select;
    }

    /**
     * @return Select[]
     */
    private function getIndexSelects(AdapterInterface $connection): array
    {
        $stockIds = $connection->fetchCol(
            $connection->select()->from($connection->getTableName('inventory_stock'), ['stock_id'])
        );
        $selects = [];
        foreach ($stockIds as $stockId) {
            $stockId = (int) $stockId;
            $table = $connection->getTableName('inventory_stock_' . $stockId);
            if ($stockId !== self::DEFAULT_STOCK_ID && !$connection->isTableExists($table)) {
                continue;
            }
            $select = $connection->select()->from(
                $table,
                [
                    'stock_id' => new \Zend_Db_Expr((string) $stockId),
                    'sku' => 'sku',
                    'stock_qty' => 'quantity',
                    'is_salable' => 'is_salable',
                ]
            );
            if ($stockId === self::DEFAULT_STOCK_ID) {
                $select->where('website_id = 0');
            }
            $selects[] = $select;
        }

        return $selects;
    }

    private function getReservationSelect(AdapterInterface $connection): Select
    {
        return $connection->select()
            ->from(
                $connection->getTableName('inventory_reservation'),
                [
                    'stock_id' => 'stock_id',
                    'sku' => 'sku',
                    'reservation_qty' => new \Zend_Db_Expr('SUM(quantity)'),
                ]
            )
            ->group(['stock_id', 'sku']);
    }

    private function getEmptySelect(AdapterInterface $connection): Select
    {
        return $connection->select()->from(
            $connection->getTableName('inventory_stock'),
            [
                'stock_id' => new \Zend_Db_Expr('NULL'),
                'sku' => new \Zend_Db_Expr('NULL'),
                'product_id' => new \Zend_Db_Expr('NULL'),
                'stock_qty' => new \Zend_Db_Expr('NULL'),
                'reserved_qty' => new \Zend_Db_Expr('NULL'),
                'salable_qty' => new \Zend_Db_Expr('NULL'),
            ]
        )->where('1 = 0');
    }
}
