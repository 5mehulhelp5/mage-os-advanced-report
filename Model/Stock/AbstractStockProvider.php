<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Stock;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Shared column set: product id, sku, qty, source code, plus store 0 cost, price and status.
 */
abstract class AbstractStockProvider implements StockProviderInterface
{
    private const array ATTRIBUTES = [
        'cost' => ['table' => 'catalog_product_entity_decimal', 'alias' => 'cost'],
        'price' => ['table' => 'catalog_product_entity_decimal', 'alias' => 'price'],
        'status' => ['table' => 'catalog_product_entity_int', 'alias' => 'is_enabled'],
    ];

    public function __construct(protected readonly ReportConnection $reportConnection)
    {
    }

    public function getSelect(AdapterInterface $connection, Dataset $dataset): Select
    {
        $select = $connection->select();
        $this->applyBase($connection, $select);
        foreach (self::ATTRIBUTES as $code => $attribute) {
            $this->joinAttribute($connection, $select, $code, $attribute['table'], $attribute['alias']);
        }

        return $select;
    }

    /**
     * Adds the stock table, joins `catalog_product_entity` as "e" and sets id, sku, qty, source_code columns.
     *
     * @param AdapterInterface $connection
     * @param Select $select
     */
    abstract protected function applyBase(AdapterInterface $connection, Select $select): void;

    private function joinAttribute(
        AdapterInterface $connection,
        Select $select,
        string $code,
        string $table,
        string $alias
    ): void {
        $attributeId = \sprintf(
            '(SELECT a.attribute_id FROM %s AS a INNER JOIN %s AS t ON t.entity_type_id = a.entity_type_id'
            . ' WHERE t.entity_type_code = %s AND a.attribute_code = %s)',
            $this->reportConnection->getTableName('eav_attribute'),
            $this->reportConnection->getTableName('eav_entity_type'),
            $connection->quote('catalog_product'),
            $connection->quote($code)
        );
        $join = \sprintf(
            '%1$s.entity_id = e.entity_id AND %1$s.store_id = 0 AND %1$s.attribute_id = %2$s',
            $code,
            $attributeId
        );
        $select->joinLeft(
            [$code => $this->reportConnection->getTableName($table)],
            $join,
            [$alias => $code . '.value']
        );
    }
}
