<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use InvalidArgumentException;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Number of distinct sales documents (shipments or credit memos) containing the order item or its configurable child.
 */
class SalesDocumentCountExpression implements FieldExpressionProviderInterface
{
    private const string TEMPLATE = '(SELECT COUNT(DISTINCT d.entity_id) FROM %1$s AS d'
        . ' INNER JOIN %2$s AS di ON di.parent_id = d.entity_id'
        . ' INNER JOIN %3$s AS oi ON oi.item_id = di.order_item_id'
        . ' WHERE d.order_id = %4$s.order_id AND di.qty > 0'
        . ' AND (oi.item_id = %4$s.item_id'
        . ' OR (oi.parent_item_id = %4$s.item_id AND %4$s.product_type = \'configurable\'))%5$s)';

    /**
     * @param int[] $excludedStates document `state` values to ignore
     */
    public function __construct(
        private readonly string $documentTable,
        private readonly string $itemTable,
        private readonly array $excludedStates = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getExpression(Dataset $dataset, AdapterInterface $connection): string
    {
        return \sprintf(
            self::TEMPLATE,
            $connection->getTableName($this->documentTable),
            $connection->getTableName($this->itemTable),
            $connection->getTableName('sales_order_item'),
            $this->getItemAlias($dataset),
            $this->buildStateCondition()
        );
    }

    private function buildStateCondition(): string
    {
        if ($this->excludedStates === []) {
            return '';
        }

        return ' AND d.state NOT IN (' . implode(', ', array_map(intval(...), $this->excludedStates)) . ')';
    }

    private function getItemAlias(Dataset $dataset): string
    {
        $expression = $dataset->getField('item_id')->sourceMappings['mysql']->expression ?? '';
        if (preg_match('/^(\w+)\.item_id$/', $expression, $matches) !== 1) {
            throw new InvalidArgumentException(
                \sprintf('Cannot derive the item alias of dataset "%s" from its item_id mapping.', $dataset->code)
            );
        }

        return $matches[1];
    }
}
