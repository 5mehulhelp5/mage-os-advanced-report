<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Rolls the values stored on the children of a dynamic-price bundle up to the bundle line.
 *
 * For dynamic-price bundles (`product_calculations` = 0) the core keeps discounts, refunds and canceled quantities
 * on the child rows. Those rows are summed by a correlated subquery that runs only for dynamic bundle lines (it
 * uses the native SALES_ORDER_ITEM_ORDER_ID index through `order_id`); every other line, fixed-price bundles
 * included, keeps its own expression. Subquery rows are aliased `bc`, the line is aliased `main`.
 */
class DynamicBundleRollupExpression implements FieldExpressionProviderInterface
{
    private const string DYNAMIC_CONDITION = "main.product_type = 'bundle'"
        . " AND (CASE WHEN JSON_VALID(main.product_options)"
        . " THEN JSON_UNQUOTE(JSON_EXTRACT(main.product_options, '$.product_calculations')) END) = '0'";

    private const string TEMPLATE = '(CASE WHEN %1$s THEN (SELECT %2$s FROM %3$s AS bc'
        . ' WHERE bc.order_id = main.order_id AND bc.parent_item_id = main.item_id) ELSE %4$s END)';

    /**
     * @param string $lineExpression expression for every other line (alias `main`)
     * @param string $childrenExpression aggregate over the children of a dynamic bundle (alias `bc`)
     */
    public function __construct(
        private readonly string $lineExpression,
        private readonly string $childrenExpression,
        private readonly ReportConnection $reportConnection,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getExpression(Dataset $dataset, AdapterInterface $connection): string
    {
        return \sprintf(
            self::TEMPLATE,
            self::DYNAMIC_CONDITION,
            $this->childrenExpression,
            $this->reportConnection->getTableName('sales_order_item'),
            $this->lineExpression
        );
    }
}
