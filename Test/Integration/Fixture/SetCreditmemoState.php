<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\TestFramework\Fixture\DataFixtureInterface;

/**
 * Forces `sales_creditmemo.state` through direct SQL. Data: `creditmemo_id`, `state`.
 */
final readonly class SetCreditmemoState implements DataFixtureInterface
{
    public function __construct(private ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function apply(array $data = []): ?DataObject
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName('sales_creditmemo'),
            ['state' => (int) $data['state']],
            ['entity_id = ?' => (int) $data['creditmemo_id']]
        );

        return null;
    }
}
