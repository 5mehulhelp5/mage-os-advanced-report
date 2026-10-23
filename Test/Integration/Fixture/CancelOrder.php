<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Fixture;

use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\TestFramework\Fixture\DataFixtureInterface;

/**
 * Cancels an order (core has no cancel fixture). Data: `order_id`.
 */
final readonly class CancelOrder implements DataFixtureInterface
{
    public function __construct(private OrderManagementInterface $orderManagement)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function apply(array $data = []): ?DataObject
    {
        $this->orderManagement->cancel((int) $data['order_id']);

        return null;
    }
}
