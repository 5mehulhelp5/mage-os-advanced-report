<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Framework\App\ResourceConnection;

/**
 * Labels stock ids with the MSI stock name (read by table name, no MSI class dependency).
 */
class StockLabelResolver extends AbstractLabelResolver
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('inventory_stock'), ['stock_id', 'name'])
            ->where('stock_id IN (?)', array_map(intval(...), $values));

        return array_map(strval(...), $connection->fetchPairs($select));
    }
}
