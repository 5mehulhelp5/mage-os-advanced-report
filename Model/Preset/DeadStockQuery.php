<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Preset;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Dead stock: products with quantity on hand and no counted sale in the last `dead_stock_days`.
 * `days_since_last_sale` is the age of the last counted sale ever, null when never sold.
 */
final class DeadStockQuery extends AbstractStockAntiJoinQuery
{
    protected function applyPredicate(AdapterInterface $connection, Select $select): void
    {
        $recentSales = $this->orderItemsOf($connection, '1', true)
            . ' AND o.created_at >= :utcSince AND '
            . $this->countedStatusCondition->build('o.status', $connection);

        $select->where('s.qty > 0');
        $select->where('NOT EXISTS (' . $recentSales . ')');
        $select->bind([
            'utcSince' => gmdate('Y-m-d H:i:s', time() - $this->config->getDeadStockDays() * 86400),
        ]);
    }

    protected function getExtraColumns(AdapterInterface $connection): array
    {
        $lastSale = $this->orderItemsOf($connection, 'DATEDIFF(UTC_TIMESTAMP(), MAX(o.created_at))', true)
            . ' AND ' . $this->countedStatusCondition->build('o.status', $connection);

        return ['days_since_last_sale' => '(' . $lastSale . ')'];
    }
}
