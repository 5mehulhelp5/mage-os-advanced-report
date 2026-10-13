<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Preset;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Never sold: enabled products with no order item row at all (any order status, parent or child).
 */
final class NeverSoldQuery extends AbstractStockAntiJoinQuery
{
    protected function applyPredicate(AdapterInterface $connection, Select $select): void
    {
        $select->where('s.is_enabled = 1');
        $select->where('NOT EXISTS (' . $this->orderItemsOf($connection, '1', false) . ')');
    }
}
