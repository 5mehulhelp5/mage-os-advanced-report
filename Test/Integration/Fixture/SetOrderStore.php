<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\TestFramework\Fixture\DataFixtureInterface;

/**
 * Moves an order to another store through direct SQL. Data: `order_id`, `store_id`.
 *
 * Updates `sales_order.store_id` and `sales_order_item.store_id`. Neither GuestCart nor CustomerCart
 * accepts a store: place the order on the default store, then move it. Store ids of extra stores
 * come from `Helper/StoreProvisioner` (unknown at attribute time), so tests usually call
 * `Bootstrap::getObjectManager()->get(SetOrderStore::class)->apply([...])` inside the test method.
 * Shared with task 009.
 */
final class SetOrderStore implements DataFixtureInterface
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function apply(array $data = []): ?DataObject
    {
        $connection = $this->resourceConnection->getConnection();
        $orderId = (int) $data['order_id'];
        $storeId = (int) $data['store_id'];
        $connection->update(
            $this->resourceConnection->getTableName('sales_order'),
            ['store_id' => $storeId],
            ['entity_id = ?' => $orderId]
        );
        $connection->update(
            $this->resourceConnection->getTableName('sales_order_item'),
            ['store_id' => $storeId],
            ['order_id = ?' => $orderId]
        );

        return null;
    }
}
