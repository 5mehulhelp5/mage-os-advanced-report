<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression;

use DateInterval;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Cart\CartState;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
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
        private readonly ReportConnection $reportConnection,
    ) {
    }

    public function converted(string $alias, AdapterInterface $connection): string
    {
        return \sprintf(
            '(EXISTS (SELECT 1 FROM %s AS so WHERE so.quote_id = %s.entity_id))',
            $this->reportConnection->getTableName('sales_order'),
            $alias
        );
    }

    public function abandoned(string $alias, AdapterInterface $connection): string
    {
        return \sprintf(
            '(%1$s.is_active = 1 AND %1$s.items_count > 0 AND %2$s IS NOT NULL AND %1$s.updated_at < %3$s AND NOT %4$s)',
            $alias,
            $this->email($alias),
            $this->abandonedThreshold($connection),
            $this->converted($alias, $connection)
        );
    }

    public function recent(string $alias, AdapterInterface $connection): string
    {
        $threshold = $connection->quote(
            $this->clock->now()->sub(new DateInterval('PT' . $this->config->getRecentCartHours() . 'H'))
                ->format(self::DATE_FORMAT)
        );

        return \sprintf(
            '(%1$s.is_active = 1 AND %1$s.items_count > 0 AND %1$s.updated_at >= %2$s AND NOT %3$s)',
            $alias,
            $threshold,
            $this->converted($alias, $connection)
        );
    }

    public function state(string $alias, AdapterInterface $connection): string
    {
        return \sprintf(
            '(CASE WHEN %7$s THEN %8$s'
            . ' WHEN %1$s.is_active = 1 AND %1$s.updated_at >= %2$s THEN %3$s'
            . ' WHEN %1$s.is_active = 1 AND %4$s IS NOT NULL THEN %5$s'
            . ' WHEN %1$s.is_active = 1 THEN %6$s ELSE %9$s END)',
            $alias,
            $this->abandonedThreshold($connection),
            $connection->quote(CartState::ACTIVE->value),
            $this->email($alias),
            $connection->quote(CartState::ABANDONED->value),
            $connection->quote(CartState::UNRECOVERABLE->value),
            $this->converted($alias, $connection),
            $connection->quote(CartState::CONVERTED->value),
            $connection->quote(CartState::INACTIVE->value)
        );
    }

    /**
     * Lowercased, trimmed quote email with the billing address fallback; NULL when the cart has no email at all.
     */
    public function customerEmail(string $alias): string
    {
        return \sprintf('LOWER(%s)', $this->email($alias));
    }

    /**
     * Quote email, falling back to the billing address email (guest checkout stores it there until the order).
     */
    private function email(string $alias): string
    {
        return \sprintf(
            "COALESCE(NULLIF(TRIM(%1\$s.customer_email), ''), (SELECT NULLIF(TRIM(qa.email), '') FROM %2\$s AS qa"
            . " WHERE qa.quote_id = %1\$s.entity_id AND qa.address_type = 'billing' LIMIT 1))",
            $alias,
            $this->reportConnection->getTableName('quote_address')
        );
    }

    private function abandonedThreshold(AdapterInterface $connection): string
    {
        return $connection->quote(
            $this->clock->now()->sub(new DateInterval('PT' . $this->config->getAbandonedAfterHours() . 'H'))
                ->format(self::DATE_FORMAT)
        );
    }
}
