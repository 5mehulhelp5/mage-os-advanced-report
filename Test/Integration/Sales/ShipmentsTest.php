<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Sales;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Test\Fixture\Shipment;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Test\Integration\Fixture\CancelOrder;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetShipmentCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Shipment metrics: units_shipped, units_to_ship and shipments (sales_items).
 *
 * The core Shipment fixture ignores `qty` (array_intersect instead of array_intersect_key: every listed item ships
 * exactly 1 unit, an empty `items` list ships every remaining unit). Partial shipments are therefore built from
 * successive 1-unit shipments.
 *
 * The order date is set after the shipments: shipping saves the order and resets `created_at`.
 *
 * Inputs (counted statuses '' = all but canceled; orders created 2026-04-08 UTC, shipments created later):
 *  - products: ar-sh-a (10.00), ar-sh-b (20.00)
 *  - order 1 (10:00): 3 x A + 1 x B; shipment 1: 1 x A (2026-04-10); shipment 2: 1 x A (2026-04-11)
 *  - order 2 (12:00): 2 x B; shipment 3: everything (2026-04-12)
 *  - cancel test (own fixtures, method-level fixtures replace the class-level ones): order 3 (14:00) 4 x A, never
 *    shipped, canceled; order 4 (15:00) 3 x A with 1 unit shipped
 *
 * Expected values (hand-computed, hard-coded in the tests):
 *  | sales_items    | A                         | B                                  |
 *  | units_shipped  | 2 = 1 + 1                 | 2 = 0 (order 1) + 2 (order 2)      |
 *  | units_to_ship  | 1 = 3 - 2 (order 1)       | 1 = 1 - 0 (order 1) + 0 (order 2)  |
 *  | shipments      | 2 (shipments 1 and 2)     | 1 (shipment 3)                     |
 *  | cancel test: units_to_ship of A = 2 = 3 - 1 (order 4); the 4 units of canceled order 3 are not backlog
 *  | range 2026-04-09..04-30 (shipment dates, not order dates): no rows, every metric 0
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('carriers/flatrate/active', '1'),
    Config('carriers/flatrate/type', 'O'),
    Config('carriers/flatrate/price', '5'),
    Config('payment/checkmo/active', '1'),
    Config('mageos_advancedreport/sales/counted_statuses', ''),
    DataFixture(Product::class, ['sku' => 'ar-sh-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
    DataFixture(Product::class, ['sku' => 'ar-sh-b', 'price' => 20, 'tax_class_id' => 0], 'productB'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 3]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-sh-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(Shipment::class, ['order_id' => '$order1.id$', 'items' => [['sku' => 'ar-sh-a', 'qty' => 1]]], 'shipment1'),
    DataFixture(SetShipmentCreatedAt::class, ['shipment_id' => '$shipment1.id$', 'created_at' => '2026-04-10 09:00:00']),
    DataFixture(Shipment::class, ['order_id' => '$order1.id$', 'items' => [['sku' => 'ar-sh-a', 'qty' => 1]]], 'shipment2'),
    DataFixture(SetShipmentCreatedAt::class, ['shipment_id' => '$shipment2.id$', 'created_at' => '2026-04-11 09:00:00']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-04-08 10:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productB.id$', 'qty' => 2]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-sh-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(Shipment::class, ['order_id' => '$order2.id$', 'items' => []], 'shipment3'),
    DataFixture(SetShipmentCreatedAt::class, ['shipment_id' => '$shipment3.id$', 'created_at' => '2026-04-12 09:00:00']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-04-08 12:00:00']),
]
final class ShipmentsTest extends TestCase
{
    private const string FROM = '2026-04-08';
    private const string TO = '2026-04-08';

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItSetsTheCreatedAtOfAShipmentFromTheFixture(): void
    {
        $this->assertSame('2026-04-10 09:00:00', $this->shipmentCreatedAt('shipment1'));
        $this->assertSame('2026-04-11 09:00:00', $this->shipmentCreatedAt('shipment2'));
        $this->assertSame('2026-04-12 09:00:00', $this->shipmentCreatedAt('shipment3'));
    }

    public function testItCountsUnitsShippedAcrossPartialShipmentsOfOneOrder(): void
    {
        $rows = $this->bySku($this->metrics(['units_shipped']));

        // A: 1 + 1 over two shipments of order 1; B: 0 (order 1) + 2 (order 2 shipped in full)
        $this->assertEqualsWithDelta(2.0, (float) $rows['ar-sh-a']['units_shipped'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $rows['ar-sh-b']['units_shipped'], 0.0001);
    }

    public function testItReportsTheRemainingBacklogAsUnitsToShipAfterAPartialShipment(): void
    {
        $rows = $this->bySku($this->metrics(['units_to_ship']));

        // A: 3 ordered - 2 shipped = 1; B: order 1 keeps 1, order 2 is fully shipped = 1
        $this->assertEqualsWithDelta(1.0, (float) $rows['ar-sh-a']['units_to_ship'], 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $rows['ar-sh-b']['units_to_ship'], 0.0001);
    }

    public function testItCountsTwoShipmentsForOneOrderLineShippedTwice(): void
    {
        $rows = $this->bySku($this->metrics(['shipments']));

        // A: shipments 1 and 2; B: only shipment 3 (order 1 line B was never shipped)
        $this->assertEquals(2, (int) $rows['ar-sh-a']['shipments']);
        $this->assertEquals(1, (int) $rows['ar-sh-b']['shipments']);
    }

    public function testItAttributesShippedUnitsToTheOrderDateNotTheShipmentDate(): void
    {
        // the shipments happened on 2026-04-10..12, the orders belong to 2026-04-08
        $onOrderDate = $this->runner->totals($this->metrics(['units_shipped', 'shipments']));
        $this->assertEqualsWithDelta(4.0, (float) $onOrderDate['units_shipped'], 0.0001);
        $this->assertEquals(3, (int) $onOrderDate['shipments']);

        $other = $this->runner->run(
            'sales_items',
            ['units_shipped', 'shipments'],
            [],
            [],
            '2026-04-09',
            '2026-04-30'
        );
        $this->assertEqualsWithDelta(0.0, (float) $this->runner->totals($other)['units_shipped'], 0.0001);
        $this->assertEquals(0, (int) $this->runner->totals($other)['shipments']);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        DataFixture(Product::class, ['sku' => 'ar-sh-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
        DataFixture(GuestCart::class, as: 'cart3'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$productA.id$', 'qty' => 4]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-sh-3@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
        DataFixture(CancelOrder::class, ['order_id' => '$order3.id$']),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-04-08 14:00:00']),
        DataFixture(GuestCart::class, as: 'cart4'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$productA.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart4.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart4.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-sh-4@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart4.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart4.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart4.id$'], 'order4'),
        DataFixture(Shipment::class, ['order_id' => '$order4.id$', 'items' => [['sku' => 'ar-sh-a', 'qty' => 1]]]),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order4.id$', 'created_at' => '2026-04-08 15:00:00']),
    ]
    public function testItExcludesCanceledOrdersFromUnitsToShip(): void
    {
        $rows = $this->bySku($this->metrics(['units_to_ship']));

        // order 3 (4 x A, canceled) is not counted: only order 4 (3 ordered - 1 shipped = 2) remains
        $this->assertEqualsWithDelta(2.0, (float) $rows['ar-sh-a']['units_to_ship'], 0.0001);
    }

    private function shipmentCreatedAt(string $alias): string
    {
        $shipmentId = (int) DataFixtureStorageManager::getStorage()->get($alias)->getId();
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        return (string) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('sales_shipment'), 'created_at')
                ->where('entity_id = ?', $shipmentId)
        );
    }

    /**
     * @param string[] $metrics
     */
    private function metrics(array $metrics): ReportResultInterface
    {
        return $this->runner->run(
            'sales_items',
            $metrics,
            [['code' => 'sku', 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function bySku(ReportResultInterface $result): array
    {
        $map = [];
        foreach ($this->runner->rows($result) as $row) {
            $map[$row['sku']] = $row;
        }

        return $map;
    }
}
