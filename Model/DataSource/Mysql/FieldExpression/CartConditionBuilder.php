<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use DateInterval;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Cart\CartState;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Query\ClockInterface;

/**
 * SQL for the quote conversion / abandonment conditions. Time thresholds are UTC literals
 * computed from the clock, never database clock functions.
 */
class CartConditionBuilder
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly Config $config,
    ) {
    }

    public function converted(string $alias, AdapterInterface $connection): string
    {
        return \sprintf(
            '(EXISTS (SELECT 1 FROM %s AS so WHERE so.quote_id = %s.entity_id))',
            $connection->getTableName('sales_order'),
            $alias
        );
    }

    public function abandoned(string $alias, AdapterInterface $connection): string
    {
        return \sprintf(
            '(%1$s.is_active = 1 AND %1$s.items_count > 0 AND %2$s AND %1$s.updated_at < %3$s)',
            $alias,
            $this->hasEmail($alias),
            $this->abandonedThreshold($connection)
        );
    }

    public function recent(string $alias, AdapterInterface $connection): string
    {
        $threshold = $connection->quote(
            $this->clock->now()->sub(new DateInterval('PT' . $this->config->getRecentCartHours() . 'H'))
                ->format(self::DATE_FORMAT)
        );

        return \sprintf(
            '(%1$s.is_active = 1 AND %1$s.items_count > 0 AND %1$s.updated_at >= %2$s)',
            $alias,
            $threshold
        );
    }

    public function state(string $alias, AdapterInterface $connection): string
    {
        return \sprintf(
            '(CASE WHEN %1$s.is_active = 1 AND %1$s.updated_at >= %2$s THEN %3$s'
            . ' WHEN %1$s.is_active = 1 AND %4$s THEN %5$s'
            . ' WHEN %1$s.is_active = 1 THEN %6$s'
            . ' WHEN %7$s THEN %8$s ELSE %9$s END)',
            $alias,
            $this->abandonedThreshold($connection),
            $connection->quote(CartState::ACTIVE->value),
            $this->hasEmail($alias),
            $connection->quote(CartState::ABANDONED->value),
            $connection->quote(CartState::UNRECOVERABLE->value),
            $this->converted($alias, $connection),
            $connection->quote(CartState::CONVERTED->value),
            $connection->quote(CartState::INACTIVE->value)
        );
    }

    private function hasEmail(string $alias): string
    {
        return \sprintf("%1\$s.customer_email IS NOT NULL AND %1\$s.customer_email <> ''", $alias);
    }

    private function abandonedThreshold(AdapterInterface $connection): string
    {
        return $connection->quote(
            $this->clock->now()->sub(new DateInterval('PT' . $this->config->getAbandonedAfterHours() . 'H'))
                ->format(self::DATE_FORMAT)
        );
    }
}
