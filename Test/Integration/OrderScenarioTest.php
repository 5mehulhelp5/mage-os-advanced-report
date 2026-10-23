<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Customer\Test\Fixture\Customer;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\CustomerCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Test\Fixture\Creditmemo;
use Magento\Sales\Test\Fixture\Invoice;
use Magento\Sales\Test\Fixture\Shipment;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Test\Integration\Fixture\CancelOrder;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic order scenario shared by correctness tests (scenario is declared by the attributes below).
 *
 * Inputs (flat rate 5.00 per order, no tax, no discount, all created 2026-01-15 12:00:00 UTC):
 *  - products: ar-simple-a (10.00), ar-simple-b (25.00), tax_class_id 0
 *  - order 1: registered customer (group 1), 2 x A + 1 x B, invoiced, 1 x A refunded -> processing
 *  - order 2: guest ar-guest-2@example.com, 1 x B, invoiced, shipped -> complete
 *  - order 3: guest ar-guest-3@example.com, 1 x A, canceled
 *  Counted statuses pinned to '' = every status except canceled -> orders 1 and 2 counted.
 *
 * Expected values (hard-coded in the tests):
 *  | report                                   | value                                   |
 *  | sales_orders orders total                | 2 (orders 1, 2)                         |
 *  | sales_orders gross_sales total           | 70.00 = (2*10 + 25) + 25                |
 *  | sales_orders returns total               | 10.00 = 1 * 10, no discount             |
 *  | orders by customer_group_id              | 0 => 1 (order 2), 1 => 1 (order 1)      |
 *  | orders by customer_type                  | 1 => 2 (both first orders), no 0 row    |
 *  | sales_items units_sold by sku            | A => 2 (order 1), B => 2 (orders 1, 2)  |
 * The credit memo also refunds order 1 shipping (5.00) by Magento default; returns is unaffected.
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
    DataFixture(Product::class, ['sku' => 'ar-simple-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
    DataFixture(Product::class, ['sku' => 'ar-simple-b', 'price' => 25, 'tax_class_id' => 0], 'productB'),
    DataFixture(Customer::class, ['addresses' => [[]]], 'customer'),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 2]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(Invoice::class, ['order_id' => '$order1.id$']),
    DataFixture(
        Creditmemo::class,
        ['order_id' => '$order1.id$', 'items' => [['qty' => 1, 'product_id' => '$productA.id$']]]
    ),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-01-15 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-guest-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(Invoice::class, ['order_id' => '$order2.id$']),
    DataFixture(Shipment::class, ['order_id' => '$order2.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-01-15 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-guest-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(CancelOrder::class, ['order_id' => '$order3.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-01-15 12:00:00']),
]
final class OrderScenarioTest extends TestCase
{
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

    public function testItCountsOnlyOrdersWithCountedStatusesInTheScenario(): void
    {
        $result = $this->runner->run('sales_orders', ['orders']);

        // orders 1 (processing) and 2 (complete); order 3 canceled
        $this->assertEquals(2, $this->runner->totals($result)['orders']);
    }

    public function testItSumsGrossSalesOfCountedScenarioOrders(): void
    {
        $result = $this->runner->run('sales_orders', ['gross_sales']);

        // order 1: 2*10 + 25 = 45; order 2: 25; total 70
        $this->assertEqualsWithDelta(70.0, $this->runner->totals($result)['gross_sales'], 0.0001);
    }

    public function testItReportsThePartialRefundAmountOfOrderOneAsReturns(): void
    {
        $result = $this->runner->run('sales_orders', ['returns']);

        // order 1: 1 x A refunded = 10, no discount
        $this->assertEqualsWithDelta(10.0, $this->runner->totals($result)['returns'], 0.0001);
    }

    public function testItSplitsCountedOrdersBetweenNotLoggedInAndGeneralCustomerGroups(): void
    {
        $result = $this->runner->run(
            'sales_orders',
            ['orders'],
            [['code' => 'customer_group_id', 'granularity' => null]]
        );

        // group 0 (guest order 2) => 1; group 1 (registered order 1) => 1
        $this->assertEquals([0 => 1, 1 => 1], $this->ordersBy($result, 'customer_group_id'));
    }

    public function testItClassifiesBothCountedScenarioOrdersAsNewCustomers(): void
    {
        $result = $this->runner->run(
            'sales_orders',
            ['orders'],
            [['code' => 'customer_type', 'granularity' => null]]
        );

        // both orders are the first counted order of their customer: 1 => 2, no 0 row
        $this->assertEquals([1 => 2], $this->ordersBy($result, 'customer_type'));
    }

    public function testItSumsUnitsOrderedPerProductInSalesItemsDataset(): void
    {
        $result = $this->runner->run(
            'sales_items',
            ['units_sold'],
            [['code' => 'sku', 'granularity' => null]]
        );

        $units = [];
        foreach ($this->runner->rows($result) as $row) {
            $units[$row['sku']] = (float) $row['units_sold'];
        }
        ksort($units);
        // A: order 1 = 2 (order 3 canceled); B: orders 1 and 2 = 1 + 1
        $this->assertEquals(['ar-simple-a' => 2.0, 'ar-simple-b' => 2.0], $units);
    }

    public function testItReturnsNoRowsForADateRangeOutsideTheScenario(): void
    {
        $result = $this->runner->run(
            'sales_orders',
            ['orders'],
            [['code' => 'customer_group_id', 'granularity' => null]],
            [],
            '2025-06-01',
            '2025-06-30'
        );

        $this->assertSame([], $this->runner->rows($result));
        $this->assertEquals(0, $this->runner->totals($result)['orders']);
    }

    /**
     * @return array<int|string,int|float>
     */
    private function ordersBy(ReportResultInterface $result, string $dimension): array
    {
        $map = [];
        foreach ($this->runner->rows($result) as $row) {
            $map[$row[$dimension]] = $row['orders'];
        }
        ksort($map);

        return $map;
    }
}
