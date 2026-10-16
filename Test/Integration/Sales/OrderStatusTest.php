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
use Magento\Sales\Test\Fixture\Creditmemo;
use Magento\Sales\Test\Fixture\Invoice;
use Magento\Sales\Test\Fixture\Shipment;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Test\Integration\Fixture\CancelOrder;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Order status semantics of sales_orders under default and custom counted statuses.
 *
 * Inputs: one simple product ar-st-a (10.00, no tax), flat rate 5.00 per order, no discount,
 * five guest orders of 1 x ar-st-a, all created 2026-02-10 12:00:00 UTC:
 *  | order | status     | how                              | subtotal | shipping | refunded subtotal | shipping refunded |
 *  | 1     | pending    | placed only                      | 10.00    | 5.00     | 0                 | 0                 |
 *  | 2     | processing | invoiced                         | 10.00    | 5.00     | 0                 | 0                 |
 *  | 3     | complete   | invoiced + shipped               | 10.00    | 5.00     | 0                 | 0                 |
 *  | 4     | canceled   | canceled                         | 10.00 (all canceled) | 5.00 (all canceled) | 0   | 0       |
 *  | 5     | closed     | invoiced + full credit memo      | 10.00    | 5.00     | 10.00             | 5.00 (default)    |
 *
 * Default counted statuses ('' = all but canceled): orders 1, 2, 3, 5.
 * A canceled order that is counted still counts in `orders` (it is an order with that status) but
 * contributes 0 gross sales, shipping and taxes.
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
    DataFixture(Product::class, ['sku' => 'ar-st-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-st-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-02-10 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-st-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(Invoice::class, ['order_id' => '$order2.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-02-10 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-st-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(Invoice::class, ['order_id' => '$order3.id$']),
    DataFixture(Shipment::class, ['order_id' => '$order3.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-02-10 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-st-4@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart4.id$'], 'order4'),
    DataFixture(CancelOrder::class, ['order_id' => '$order4.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order4.id$', 'created_at' => '2026-02-10 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart5'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart5.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart5.id$', 'email' => 'ar-st-5@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart5.id$'], 'order5'),
    DataFixture(Invoice::class, ['order_id' => '$order5.id$']),
    DataFixture(
        Creditmemo::class,
        ['order_id' => '$order5.id$', 'items' => [['qty' => 1, 'product_id' => '$product.id$']]]
    ),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order5.id$', 'created_at' => '2026-02-10 12:00:00']),
]
final class OrderStatusTest extends TestCase
{
    private const string FROM = '2026-02-01';
    private const string TO = '2026-02-28';

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

    public function testItCountsEveryStatusExceptCanceledWithTheDefaultCountedStatuses(): void
    {
        $totals = $this->totals(['orders']);

        // pending, processing, complete, closed; canceled excluded
        $this->assertEquals(4, $totals['orders']);
    }

    public function testItSumsGrossSalesOfPendingProcessingCompleteAndClosedOrdersByDefault(): void
    {
        $totals = $this->totals(['gross_sales']);

        // 4 counted orders x 10.00
        $this->assertEqualsWithDelta(40.0, $totals['gross_sales'], 0.0001);
    }

    public function testItReportsClosedOrderGrossSalesFullyOffsetByReturns(): void
    {
        $totals = $this->totals(['gross_sales', 'returns', 'net_sales']);

        // returns: only the closed order, 10.00 refunded subtotal; net = 40 - 0 - 10
        $this->assertEqualsWithDelta(10.0, $totals['returns'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $totals['net_sales'], 0.0001);
    }

    #[Config('mageos_advancedreport/sales/counted_statuses', 'complete,closed')]
    public function testItCountsOnlyListedStatusesWithACustomCountedStatusList(): void
    {
        $totals = $this->totals(['orders', 'gross_sales']);

        // orders 3 (complete) and 5 (closed): 2 orders, 2 x 10.00
        $this->assertEquals(2, $totals['orders']);
        $this->assertEqualsWithDelta(20.0, $totals['gross_sales'], 0.0001);
    }

    public function testItGroupsOrdersByStatusDimensionWithOneRowPerCountedStatus(): void
    {
        $result = $this->runner->run(
            'sales_orders',
            ['orders'],
            [['code' => 'status', 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );

        $byStatus = [];
        foreach ($this->runner->rows($result) as $row) {
            $byStatus[$row['status']] = (int) $row['orders'];
        }
        ksort($byStatus);
        // one order per counted status, no canceled row
        $this->assertEquals(['closed' => 1, 'complete' => 1, 'pending' => 1, 'processing' => 1], $byStatus);
    }

    #[Config('mageos_advancedreport/sales/counted_statuses', 'canceled')]
    public function testItReportsZeroGrossSalesShippingAndTaxesForACanceledOrderWhenCanceledIsCounted(): void
    {
        $totals = $this->totals(['orders', 'gross_sales', 'shipping', 'taxes']);

        // the canceled order is counted as an order, but 10.00 - 10.00 canceled subtotal,
        // 5.00 - 5.00 canceled shipping, 0 tax
        $this->assertEquals(1, $totals['orders']);
        $this->assertEqualsWithDelta(0.0, (float) $totals['gross_sales'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $totals['shipping'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $totals['taxes'], 0.0001);
    }

    /**
     * @param string[] $metrics
     * @return array<string,mixed>
     */
    private function totals(array $metrics): array
    {
        return $this->runner->totals($this->runner->run('sales_orders', $metrics, [], [], self::FROM, self::TO));
    }
}
