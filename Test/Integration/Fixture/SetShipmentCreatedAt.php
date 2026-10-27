<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\TestFramework\Fixture\DataFixtureInterface;

/**
 * Forces `sales_shipment.created_at` through direct SQL. Data: `shipment_id`, `created_at` (UTC).
 */
final readonly class SetShipmentCreatedAt implements DataFixtureInterface
{
    public function __construct(private ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function apply(array $data = []): ?DataObject
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('sales_shipment'),
            ['created_at' => $data['created_at']],
            ['entity_id = ?' => (int) $data['shipment_id']]
        );

        return null;
    }
}
