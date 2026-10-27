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
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetShipmentCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Credit memo count with several memos per order, average shipping cost and average wait days.
 *
 * Every method declares its own full fixture chain and `#[Config]` (method-level fixtures replace class-level ones,
 * R25). The single-memo case of `credit_memos` is asserted in `RefundsTest`; `first_order` is asserted in the
 * customer tests (`CustomerMetricsTest`).
 *
 * Flat rate 5.00 is charged per ordered unit in this environment; no tax, no discount, counted statuses '' (all but
 * canceled), orders created 2026-04-08 UTC, product ar-sd-a (10.00). The shipment fixture resets the order date, so
 * `SetOrderCreatedAt` comes after the shipments.
 *
 * Expected values (hand-computed, hard-coded in the tests):
 *  | test                  | inputs                                                       | expected                     |
 *  | credit memos          | order 1 (3 x A, invoiced), memo 1 x A twice                  | credit_memos A = 2           |
 *  | avg shipping cost     | order 1 (10:00) 2 x A, order 2 (12:00) 1 x A                 | (10 + 5) / 2 = 7.5           |
 *  | avg wait days         | o1 10:00 shipped 04-09 22:00 (36 h), o2 12:00 shipped 04-09 12:00 (24 h) | (1.5 + 1.0) / 2 = 1.25 |
 *  | later shipments       | order 1 (2 x A) 10:00; shipments 04-12 10:00 and 04-09 10:00 | first = 04-09, 1 day = 1.0   |
 *  | unshipped excluded    | o1 10:00 shipped 04-09 22:00 (1.5 days); o2 never shipped    | 1.5 (not 0.75), orders = 2   |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
]
final class SalesDocumentsTest extends TestCase
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

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        DataFixture(Product::class, ['sku' => 'ar-sd-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-sd-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(Invoice::class, ['order_id' => '$order1.id$']),
        DataFixture(Creditmemo::class, ['order_id' => '$order1.id$', 'items' => [['qty' => 1, 'product_id' => '$productA.id$']], 'arguments' => ['shipping_amount' => 0]]),
        DataFixture(Creditmemo::class, ['order_id' => '$order1.id$', 'items' => [['qty' => 1, 'product_id' => '$productA.id$']], 'arguments' => ['shipping_amount' => 0]]),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-04-08 10:00:00']),
    ]
    public function testItCountsTwoCreditMemosForOneOrderRefundedInTwoSteps(): void
    {
        $result = $this->runner->run(
            'sales_items',
            ['credit_memos'],
            [['code' => 'sku', 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );
        $rows = $this->runner->rows($result);

        $this->assertCount(1, $rows);
        $this->assertEquals(2, (int) $rows[0]['credit_memos']);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        DataFixture(Product::class, ['sku' => 'ar-sd-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 2]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-sd-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-04-08 10:00:00']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-sd-3@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-04-08 12:00:00']),
    ]
    public function testItComputesAverageShippingCostAsShippingAmountOverCountedOrders(): void
    {
        $totals = $this->customerTotals(['avg_shipping_cost', 'orders']);

        // shipping 5 x 2 + 5 x 1 = 15 over 2 orders
        $this->assertEquals(2, (int) $totals['orders']);
        $this->assertEqualsWithDelta(7.5, (float) $totals['avg_shipping_cost'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        DataFixture(Product::class, ['sku' => 'ar-sd-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-sd-4@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(Shipment::class, ['order_id' => '$order1.id$', 'items' => []], 'shipment1'),
        DataFixture(SetShipmentCreatedAt::class, ['shipment_id' => '$shipment1.id$', 'created_at' => '2026-04-09 22:00:00']),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-04-08 10:00:00']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-sd-5@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(Shipment::class, ['order_id' => '$order2.id$', 'items' => []], 'shipment2'),
        DataFixture(SetShipmentCreatedAt::class, ['shipment_id' => '$shipment2.id$', 'created_at' => '2026-04-09 12:00:00']),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-04-08 12:00:00']),
    ]
    public function testItComputesAverageWaitDaysFromOrderDateToFirstShipmentDate(): void
    {
        $totals = $this->customerTotals(['avg_wait_days']);

        // order 1 waited 36 h = 1.5 days, order 2 waited 24 h = 1.0 day
        $this->assertEqualsWithDelta(1.25, (float) $totals['avg_wait_days'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        DataFixture(Product::class, ['sku' => 'ar-sd-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 2]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-sd-6@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(Shipment::class, ['order_id' => '$order1.id$', 'items' => [['sku' => 'ar-sd-a', 'qty' => 1]]], 'shipment1'),
        DataFixture(SetShipmentCreatedAt::class, ['shipment_id' => '$shipment1.id$', 'created_at' => '2026-04-12 10:00:00']),
        DataFixture(Shipment::class, ['order_id' => '$order1.id$', 'items' => [['sku' => 'ar-sd-a', 'qty' => 1]]], 'shipment2'),
        DataFixture(SetShipmentCreatedAt::class, ['shipment_id' => '$shipment2.id$', 'created_at' => '2026-04-09 10:00:00']),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-04-08 10:00:00']),
    ]
    public function testItIgnoresLaterShipmentsWhenComputingWaitDays(): void
    {
        $totals = $this->customerTotals(['avg_wait_days']);

        // the earliest shipment (04-09 10:00, created second) is 24 h after the order: 1.0 day, not 4 days
        $this->assertEqualsWithDelta(1.0, (float) $totals['avg_wait_days'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        DataFixture(Product::class, ['sku' => 'ar-sd-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-sd-7@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(Shipment::class, ['order_id' => '$order1.id$', 'items' => []], 'shipment1'),
        DataFixture(SetShipmentCreatedAt::class, ['shipment_id' => '$shipment1.id$', 'created_at' => '2026-04-09 22:00:00']),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-04-08 10:00:00']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-sd-8@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-04-08 12:00:00']),
    ]
    public function testItExcludesOrdersWithoutShipmentFromAverageWaitDays(): void
    {
        $totals = $this->customerTotals(['avg_wait_days', 'orders']);

        // only order 1 is shipped (1.5 days); the unshipped order 2 is ignored, not counted as 0
        $this->assertEquals(2, (int) $totals['orders']);
        $this->assertEqualsWithDelta(1.5, (float) $totals['avg_wait_days'], 0.0001);
    }

    /**
     * @param string[] $metrics
     * @return array<string,mixed>
     */
    private function customerTotals(array $metrics): array
    {
        return $this->runner->totals(
            $this->runner->run('customer_orders', $metrics, [], [], self::FROM, self::TO)
        );
    }
}
