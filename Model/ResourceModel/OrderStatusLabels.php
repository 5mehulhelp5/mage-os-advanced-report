<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class OrderStatusLabels
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Returns a status => label map, using the store view label when set and the admin label otherwise.
     *
     * @return array<string, string>
     */
    public function getLabels(?int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(['s' => $this->resourceConnection->getTableName('sales_order_status')], []);

        $labelExpression = 's.label';
        if ($storeId) {
            $select->joinLeft(
                ['l' => $this->resourceConnection->getTableName('sales_order_status_label')],
                $connection->quoteInto('l.status = s.status AND l.store_id = ?', $storeId),
                []
            );
            $labelExpression = "COALESCE(NULLIF(l.label, ''), s.label)";
        }
        $select->columns(['s.status', $labelExpression]);

        return array_map(strval(...), $connection->fetchPairs($select));
    }
}
