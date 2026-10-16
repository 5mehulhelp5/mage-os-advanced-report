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
use Magento\Sales\Test\Fixture\Invoice;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Test\Integration\Fixture\CancelOrder;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * `sales_orders.units` is net of canceled quantities (flat rate 5.00, no tax, product ar-uc-a 10.00).
 *
 * Inputs (counted statuses pinned per test method to pending,processing,complete,closed,canceled so the final status
 * chosen by core after cancellation does not matter):
 *  - order X (2026-01-15): 3 units, 1 invoiced, then canceled -> core cancels min(qty_to_invoice, qty_to_ship) = 2
 *  - order Y (2026-01-16): 2 units, canceled entirely -> 2 canceled
 *  - order Z (2026-01-17): 1 unit, invoiced, no cancellation
 *
 * Expected values (hard-coded in the tests):
 *  | report                          | value                                    |
 *  | units, 2026-01-15               | 1 = 3 - 2 (order X)                      |
 *  | units, 2026-01-16               | 0 = 2 - 2 (order Y)                      |
 *  | units, 2026-01-17               | 1 = 1 - 0 (order Z)                      |
 *  | units, 2026-01-15..2026-01-17   | 2 = 1 + 0 + 1, equals sales_items units_sold |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('carriers/flatrate/active', '1'),
    Config('carriers/flatrate/type', 'O'),
    Config('carriers/flatrate/price', '5'),
    Config('payment/checkmo/active', '1'),
    DataFixture(Product::class, ['sku' => 'ar-uc-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 3]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-uc-x@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(
        Invoice::class,
        ['order_id' => '$order1.id$', 'items' => [['product_id' => '$product.id$', 'qty' => 1]]]
    ),
    DataFixture(CancelOrder::class, ['order_id' => '$order1.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-01-15 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 2]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-uc-y@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(CancelOrder::class, ['order_id' => '$order2.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-01-16 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-uc-z@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(Invoice::class, ['order_id' => '$order3.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-01-17 12:00:00']),
]
final class UnitsCanceledTest extends TestCase
{
    private const string COUNTED = 'pending,processing,complete,closed,canceled';

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

    #[Config('mageos_advancedreport/sales/counted_statuses', self::COUNTED)]
    public function testItCancelsTwoOfThreeUnitsOfThePartiallyInvoicedOrderX(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $row = $connection->fetchRow(
            'SELECT o.status, i.qty_ordered, i.qty_invoiced, i.qty_canceled FROM sales_order AS o'
            . ' JOIN sales_order_item AS i ON i.order_id = o.entity_id WHERE o.customer_email = ?',
            ['ar-uc-x@example.com']
        );

        // core cancels min(qty_to_invoice 2, qty_to_ship 3) = 2 and keeps the order open for the invoiced unit
        $this->assertEquals(
            ['status' => 'processing', 'qty_ordered' => 3, 'qty_invoiced' => 1, 'qty_canceled' => 2],
            array_map(floatval(...), array_diff_key($row, ['status' => 1])) + ['status' => $row['status']]
        );
    }

    #[Config('mageos_advancedreport/sales/counted_statuses', self::COUNTED)]
    public function testItSubtractsCanceledQuantitiesFromUnitsOfAPartiallyCanceledOrder(): void
    {
        // order X: 3 ordered - 2 canceled
        $this->assertEqualsWithDelta(1.0, $this->units('2026-01-15', '2026-01-15'), 0.0001);
    }

    #[Config('mageos_advancedreport/sales/counted_statuses', self::COUNTED)]
    public function testItReportsZeroUnitsForAFullyCanceledOrderWhenCanceledIsCounted(): void
    {
        $result = $this->runner->run('sales_orders', ['orders', 'units'], [], [], '2026-01-16', '2026-01-16');

        $totals = $this->runner->totals($result);
        // order Y is counted (orders = 1) and contributes 2 - 2 = 0 units
        $this->assertEquals(1, $totals['orders']);
        $this->assertEqualsWithDelta(0.0, (float) $totals['units'], 0.0001);
    }

    #[Config('mageos_advancedreport/sales/counted_statuses', self::COUNTED)]
    public function testItMatchesSalesItemsUnitsSoldForTheSameOrders(): void
    {
        $items = $this->runner->run('sales_items', ['units_sold'], [], [], '2026-01-15', '2026-01-17');

        // X: 1, Y: 0, Z: 1
        $this->assertEqualsWithDelta(2.0, (float) $this->runner->totals($items)['units_sold'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $this->units('2026-01-15', '2026-01-17'), 0.0001);
    }

    #[Config('mageos_advancedreport/sales/counted_statuses', self::COUNTED)]
    public function testItKeepsUnitsUnchangedForOrdersWithoutCancellations(): void
    {
        // order Z: 1 ordered - 0 canceled
        $this->assertEqualsWithDelta(1.0, $this->units('2026-01-17', '2026-01-17'), 0.0001);
    }

    private function units(string $from, string $to): float
    {
        $result = $this->runner->run('sales_orders', ['units'], [], [], $from, $to);

        return (float) $this->runner->totals($result)['units'];
    }
}
