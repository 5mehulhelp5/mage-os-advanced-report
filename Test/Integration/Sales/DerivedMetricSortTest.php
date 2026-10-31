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
use Magento\Quote\Test\Fixture\AddProductToCart;
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
 * Sorting by a derived metric whose formula references other derived metrics (aov = net_sales / orders,
 * net_sales = gross_sales - discounts - returns) runs in SQL mode.
 *
 * Inputs (no tax, no discount, counted statuses '' = all but canceled, created 2026-05-12 12:00 UTC):
 *  - checkmo: 3 orders of 100 -> net_sales 300, aov 100
 *  - cashondelivery: 1 order of 200 -> net_sales 200, aov 200
 * Sorting by aov descending puts cashondelivery first, sorting by net_sales descending would put checkmo first.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
]
final class DerivedMetricSortTest extends TestCase
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

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('payment/cashondelivery/active', '1', 'store', 'default'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        DataFixture(Product::class, ['sku' => 'ar-ds-a', 'price' => 100, 'tax_class_id' => 0], 'productA'),
        DataFixture(Product::class, ['sku' => 'ar-ds-b', 'price' => 200, 'tax_class_id' => 0], 'productB'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ds-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-ds-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart3'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-ds-3@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart4'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart4.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart4.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-ds-4@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart4.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart4.id$', 'method' => ['method' => 'cashondelivery']]),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart4.id$'], 'order4'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order4.id$', 'created_at' => '2026-05-12 12:00:00']),
    ]
    public function testItSortsSalesByPaymentMethodByAverageOrderValueInSqlMode(): void
    {
        $result = $this->runner->runWith(
            'sales_orders',
            ['orders', 'net_sales', 'aov'],
            [['code' => 'payment_method', 'granularity' => null]],
            [['by' => 'aov', 'direction' => 'desc']],
            true,
            ['dateRange' => ['type' => 'custom', 'from' => '2026-05-12', 'to' => '2026-05-12']]
        );
        $rows = $this->runner->rows($result);

        $this->assertSame(['cashondelivery', 'checkmo'], array_column($rows, 'payment_method'));
        $this->assertEqualsWithDelta(200.0, (float) $rows[0]['aov'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $rows[1]['aov'], 0.0001);
    }
}
