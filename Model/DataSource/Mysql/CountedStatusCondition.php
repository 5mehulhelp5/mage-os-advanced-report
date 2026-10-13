<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Config;

/**
 * SQL condition selecting the order statuses counted as sales.
 */
class CountedStatusCondition
{
    public const string DEFAULT_EXCLUDED_STATUS = 'canceled';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Condition on the given status expression: configured statuses, or everything but canceled.
     *
     * @param string $statusExpression
     * @param AdapterInterface $connection
     */
    public function build(string $statusExpression, AdapterInterface $connection): string
    {
        $statuses = $this->config->getCountedStatuses();
        if ($statuses === []) {
            return $connection->quoteInto($statusExpression . ' NOT IN (?)', [self::DEFAULT_EXCLUDED_STATUS]);
        }

        return $connection->quoteInto($statusExpression . ' IN (?)', $statuses);
    }
}
