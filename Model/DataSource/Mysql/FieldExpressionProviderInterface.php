<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Computes the SQL expression of a field mapped with `<mysql expressionProvider="..."/>`.
 */
interface FieldExpressionProviderInterface
{
    /**
     * SQL expression for the field, valid inside a select on the dataset's base table.
     *
     * @param Dataset $dataset
     * @param AdapterInterface $connection
     */
    public function getExpression(Dataset $dataset, AdapterInterface $connection): string;
}
