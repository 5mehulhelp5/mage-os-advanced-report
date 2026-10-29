<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\TestFramework\Fixture\DataFixtureInterface;

/**
 * Forces `quote` columns through direct SQL. Data: `cart_id`, `updated_at` (UTC), optional `is_active`, `items_count`,
 * `customer_email`, `customer_is_guest`.
 *
 * `quote.updated_at` is `ON UPDATE CURRENT_TIMESTAMP`: any later UPDATE of the row resets it to the real
 * time. Every column is therefore set in ONE update that always includes `updated_at`, and this fixture must
 * be declared LAST for its quote (after AddProductToCart, SetGuestEmail and PlaceOrder, which all re-save it).
 */
final readonly class SetQuoteUpdatedAt implements DataFixtureInterface
{
    private const array OPTIONAL_COLUMNS = ['is_active', 'customer_email', 'customer_is_guest', 'items_count'];

    public function __construct(private ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function apply(array $data = []): ?DataObject
    {
        $bind = ['updated_at' => $data['updated_at']];
        foreach (self::OPTIONAL_COLUMNS as $column) {
            if (\array_key_exists($column, $data)) {
                $bind[$column] = $data[$column];
            }
        }

        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName('quote'),
            $bind,
            ['entity_id = ?' => (int) $data['cart_id']]
        );

        return null;
    }
}
