<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use InvalidArgumentException;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Days between the order creation and its earliest shipment (never negative); NULL when the order is unshipped.
 */
class FirstShipmentWaitExpression implements FieldExpressionProviderInterface
{
    private const string TEMPLATE = '(SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, %1$s.created_at, MIN(sh.created_at)))'
        . ' / 86400 FROM %2$s AS sh WHERE sh.order_id = %1$s.entity_id)';

    public function __construct(private readonly ReportConnection $reportConnection)
    {
    }

    /**
     * @inheritDoc
     */
    public function getExpression(Dataset $dataset, AdapterInterface $connection): string
    {
        return \sprintf(
            self::TEMPLATE,
            $this->getOrderAlias($dataset),
            $this->reportConnection->getTableName('sales_shipment')
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
