<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Cart converted expression over the quote table aliased `$alias`.
 */
class CartConvertedExpression implements FieldExpressionProviderInterface
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
        return $this->builder->converted($this->alias, $connection);
    }
}
