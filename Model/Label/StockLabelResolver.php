<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Framework\App\ResourceConnection;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;

/**
 * Labels stock ids with the MSI stock name (read by table name, no MSI class dependency).
 */
class StockLabelResolver extends AbstractLabelResolver
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ReportConnection $reportConnection,
        private readonly Guardrails $guardrails,
    ) {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $connection = $this->reportConnection->get();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('inventory_stock'), ['stock_id', 'name'])
            ->where('stock_id IN (?)', array_map(intval(...), $values));

        return array_map(strval(...), $connection->fetchPairs($this->guardrails->applyTimeout((string) $select), $select->getBind()));
    }
}
