<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Cart abandoned expression over the quote table aliased `$alias`.
 */
class CartAbandonedExpression implements FieldExpressionProviderInterface
{
    public function __construct(
        private readonly CartConditionBuilder $builder,
        private readonly string $alias = 'main',
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getExpression(Dataset $dataset, AdapterInterface $connection): string
    {
        return $this->builder->abandoned($this->alias, $connection);
    }
}
