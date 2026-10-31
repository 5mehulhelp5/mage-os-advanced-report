<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use InvalidArgumentException;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * 1 when no earlier counted order exists for the same customer (by id; for guests by email, whatever the earlier order's customer), else 0.
 */
class FirstOrderExpression implements FieldExpressionProviderInterface
{
    private const string EARLIER_ORDER = 'NOT EXISTS (SELECT 1 FROM %1$s AS p WHERE %2$s'
        . ' AND p.created_at <= %3$s.created_at'
        . ' AND (p.created_at < %3$s.created_at OR p.entity_id < %3$s.entity_id)'
        . ' AND %4$s)';

    public function __construct(
        private readonly CountedStatusCondition $countedStatusCondition,
        private readonly ReportConnection $reportConnection,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getExpression(Dataset $dataset, AdapterInterface $connection): string
    {
        $alias = $this->getOrderAlias($dataset);
        $table = $this->reportConnection->getTableName('sales_order');
        $status = $this->countedStatusCondition->build('p.status', $connection);

        return \sprintf(
            'CASE WHEN %1$s.customer_id IS NOT NULL THEN %2$s ELSE %3$s END',
            $alias,
            \sprintf(self::EARLIER_ORDER, $table, 'p.customer_id = ' . $alias . '.customer_id', $alias, $status),
            \sprintf(
                self::EARLIER_ORDER,
                $table,
                'p.customer_email = ' . $alias . '.customer_email',
                $alias,
                $status
            )
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
