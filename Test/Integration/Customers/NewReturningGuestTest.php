<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Customers;

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
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * New vs returning for guest orders whose earlier orders were placed by a registered account.
 *
 * Inputs (product ar-nr-a 10.00, timezone UTC, all orders at 12:00:00 UTC):
 *  | order | customer                       | date       |
 *  | 1     | C1 ar-nr-c1@example.com        | 2026-03-01 |
 *  | 2     | C1                             | 2026-03-02 |
 *  | 3     | guest ar-nr-c1@example.com     | 2026-03-03 |
 *  | 4     | guest ar-nr-g@example.com      | 2026-03-04 |
 *
 * Expected: order 3 is returning (an earlier registered order has the same email), order 4 is new, order 1 is new
 * and order 2 is returning (matched by customer id).
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('general/locale/timezone', 'UTC'),
    Config('general/locale/timezone', 'UTC', 'website', 'base'),
    DataFixture(Product::class, ['sku' => 'ar-nr-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(
        Customer::class,
        ['email' => 'ar-nr-c1@example.com', 'addresses' => [[]]],
        'customer1'
    ),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer1.id$'], 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-03-01 12:00:00']),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer1.id$'], 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-03-02 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-nr-c1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-03-03 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-nr-g@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart4.id$'], 'order4'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order4.id$', 'created_at' => '2026-03-04 12:00:00']),
]
final class NewReturningGuestTest extends TestCase
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

    public function testItCountsAGuestOrderAsReturningWhenAnEarlierRegisteredOrderHasTheSameEmail(): void
    {
        $totals = $this->totals('2026-03-03', '2026-03-03');

        $this->assertEquals(1, $totals['orders']);
        $this->assertEquals(0, $totals['new_customer_orders']);
        $this->assertEquals(1, $totals['returning_customer_orders']);
    }

    public function testItCountsAGuestOrderAsNewWhenNoEarlierOrderHasTheSameEmail(): void
    {
        $totals = $this->totals('2026-03-04', '2026-03-04');

        $this->assertEquals(1, $totals['orders']);
        $this->assertEquals(1, $totals['new_customer_orders']);
        $this->assertEquals(0, $totals['returning_customer_orders']);
    }

    public function testItKeepsRegisteredCustomersMatchedByCustomerId(): void
    {
        $first = $this->totals('2026-03-01', '2026-03-01');
        $second = $this->totals('2026-03-02', '2026-03-02');

        $this->assertEquals(1, $first['new_customer_orders']);
        $this->assertEquals(0, $first['returning_customer_orders']);
        $this->assertEquals(0, $second['new_customer_orders']);
        $this->assertEquals(1, $second['returning_customer_orders']);
    }

    /**
     * @return array<string,mixed>
     */
    private function totals(string $from, string $to): array
    {
        return $this->runner->totals($this->runner->run(
            'sales_orders',
            ['orders', 'new_customer_orders', 'returning_customer_orders'],
            [],
            [],
            $from,
            $to
        ));
    }
}
