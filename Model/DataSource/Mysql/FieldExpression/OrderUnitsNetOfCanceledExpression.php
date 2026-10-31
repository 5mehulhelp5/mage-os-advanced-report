<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use InvalidArgumentException;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Units of an order: ordered quantity minus the quantity canceled on its parent items.
 *
 * `sales_order.total_qty_ordered` sums parent items only (the quote totals collector skips children), so the
 * canceled quantity is taken from the same item set (`parent_item_id IS NULL`).
 */
class OrderUnitsNetOfCanceledExpression implements FieldExpressionProviderInterface
{
    public function __construct(private readonly ReportConnection $reportConnection)
    {
    }

    /**
     * @inheritDoc
     */
    public function getExpression(Dataset $dataset, AdapterInterface $connection): string
    {
        $alias = $this->getOrderAlias($dataset);

        return \sprintf(
            '(COALESCE(%1$s.total_qty_ordered, 0) - (SELECT COALESCE(SUM(oi.qty_canceled), 0) FROM %2$s AS oi'
            . ' WHERE oi.order_id = %1$s.entity_id AND oi.parent_item_id IS NULL))',
            $alias,
            $this->reportConnection->getTableName('sales_order_item')
        );
    }

    private function getOrderAlias(Dataset $dataset): string
    {
        $expression = $dataset->getField(Dataset::FIELD_CREATED_AT)->sourceMappings['mysql']->expression ?? '';
        if (preg_match('/^(\w+)\.created_at$/', $expression, $matches) !== 1) {
            throw new InvalidArgumentException(
                \sprintf('Cannot derive the order alias of dataset "%s" from its created_at mapping.', $dataset->code)
            );
        }

        return $matches[1];
    }
}
