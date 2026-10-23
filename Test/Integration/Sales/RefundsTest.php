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
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Partial and full refunds, refunded quantities and credit-memo adjustments.
 *
 * Inputs (flat rate 5.00 applied per ordered unit in this environment (order shipping = 5 x units), no tax, no discount, counted statuses '' = all but canceled,
 * all orders created 2026-04-08 UTC; the credit memos are created "now", outside the asserted range):
 *  - products: ar-rf-a (10.00), ar-rf-b (30.00)
 *  - order 1 (10:00): 3 x A + 1 x B, invoiced; credit memo: 1 x A, shipping refund 0, adjustment refund 2.00, fee 1.00
 *  - order 2 (12:00): 1 x B, invoiced; full credit memo (all items + full shipping 5.00) -> closed
 *  - order 3 (14:00): 2 x A, invoiced, no refund
 *
 * Expected values (hand-computed, hard-coded in the tests):
 *  | sales_orders         | order 1                    | order 2        | order 3 | total |
 *  | gross_sales          | 60 = 3*10 + 30             | 30             | 20      | 110   |
 *  | returns              | 10 = 1*10 (no discount)    | 30 = 1*30      | 0       | 40    |
 *  | shipping (net)       | 20 = 5*4 - 0 refunded      | 0 = 5*1 - 5    | 10 = 5*2| 30    |
 *  | adjustments          | 1 = 2 - 1                  | 0              | 0       | 1     |
 *  | net_sales            | 50 = 60 - 0 - 10           | 0 = 30 - 30    | 20      | 70    |
 *  | total_sales          | 69 = 50 + 20 + 0 - 1       | 0 = 0 + 0 + 0  | 30 = 20 + 10 | 99 |
 *  | sales_items          | A                          | B              |         |       |
 *  | units_sold           | 5 = 3 + 2                  | 2 = 1 + 1      |         |       |
 *  | units_refunded       | 1 (order 1)                | 1 (order 2)    |         |       |
 *  | gross_sales          | 50 = 5*10                  | 60 = 2*30      |         |       |
 *  | returns              | 10                         | 30             |         |       |
 *  | return_rate          | 0.2 = 10 / 50              | 0.5 = 30 / 60  |         |       |
 *  | credit_memos         | 1 (memo of order 1)        | 1 (order 2)    |         |       |
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
    DataFixture(Product::class, ['sku' => 'ar-rf-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
    DataFixture(Product::class, ['sku' => 'ar-rf-b', 'price' => 30, 'tax_class_id' => 0], 'productB'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 3]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-rf-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(Invoice::class, ['order_id' => '$order1.id$']),
    DataFixture(
        Creditmemo::class,
        [
            'order_id' => '$order1.id$',
            'items' => [['qty' => 1, 'product_id' => '$productA.id$']],
            'arguments' => ['shipping_amount' => 0, 'adjustment_positive' => 2, 'adjustment_negative' => 1],
        ]
    ),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-04-08 10:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-rf-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(Invoice::class, ['order_id' => '$order2.id$']),
    DataFixture(Creditmemo::class, ['order_id' => '$order2.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-04-08 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$productA.id$', 'qty' => 2]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-rf-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(Invoice::class, ['order_id' => '$order3.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-04-08 14:00:00']),
]
final class RefundsTest extends TestCase
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

    public function testItReportsPartialRefundAmountNetOfRefundedDiscountAsReturns(): void
    {
        $byHour = $this->metricByHour('returns');

        // order 1: 1 x A = 10, no discount to net off
        $this->assertEqualsWithDelta(10.0, $byHour['order1'], 0.0001);
    }

    public function testItReportsAFullRefundAsReturnsEqualToGrossSalesOfThatOrder(): void
    {
        $returns = $this->metricByHour('returns');
        $gross = $this->metricByHour('gross_sales');

        // order 2: 1 x B = 30 refunded of 30 sold
        $this->assertEqualsWithDelta(30.0, $returns['order2'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $gross['order2'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->metricByHour('net_sales')['order2'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->metricByHour('total_sales')['order2'], 0.0001);
        $this->assertEqualsWithDelta(40.0, $this->runner->totals($this->orders('returns'))['returns'], 0.0001);
    }

    public function testItSubtractsRefundedShippingFromShipping(): void
    {
        $shipping = $this->metricByHour('shipping');

        // order 1: 5*4 - 0 refunded = 20; order 2: 5*1 - 5 refunded = 0; order 3: 5*2 = 10
        $this->assertEqualsWithDelta(20.0, $shipping['order1'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $shipping['order2'], 0.0001);
        $this->assertEqualsWithDelta(10.0, $shipping['order3'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $this->runner->totals($this->orders('shipping'))['shipping'], 0.0001);
    }

    public function testItReportsAdjustmentsAsRefundAdjustmentMinusAdjustmentFee(): void
    {
        $adjustments = $this->metricByHour('adjustments');

        // order 1: 2 - 1 = 1; total_sales 50 + 20 + 0 - 1 = 69
        $this->assertEqualsWithDelta(1.0, $adjustments['order1'], 0.0001);
        $this->assertEqualsWithDelta(69.0, $this->metricByHour('total_sales')['order1'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $this->runner->totals($this->orders('adjustments'))['adjustments'], 0.0001);
        // order 3 has no refund: 20 + 10 = 30; overall 70 + 30 - 1 = 99
        $this->assertEqualsWithDelta(30.0, $this->metricByHour('total_sales')['order3'], 0.0001);
        $this->assertEqualsWithDelta(99.0, $this->runner->totals($this->orders('total_sales'))['total_sales'], 0.0001);
    }

    public function testItReportsRefundedUnitsPerProductIndependentlyOfRefundedAmounts(): void
    {
        $result = $this->runner->run(
            'sales_items',
            ['units_sold', 'units_refunded', 'returns', 'credit_memos'],
            [['code' => 'sku', 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );
        $rows = $this->bySku($result);

        // A: 1 unit refunded (10.00); B: 1 unit refunded (30.00): equal units, different amounts
        $this->assertEqualsWithDelta(1.0, (float) $rows['ar-rf-a']['units_refunded'], 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $rows['ar-rf-b']['units_refunded'], 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $rows['ar-rf-a']['returns'], 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $rows['ar-rf-b']['returns'], 0.0001);
        // units sold A = 3 + 2, B = 1 + 1; one credit memo each
        $this->assertEqualsWithDelta(5.0, (float) $rows['ar-rf-a']['units_sold'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $rows['ar-rf-b']['units_sold'], 0.0001);
        $this->assertEquals(1, (int) $rows['ar-rf-a']['credit_memos']);
        $this->assertEquals(1, (int) $rows['ar-rf-b']['credit_memos']);
    }

    public function testItComputesReturnRatePerProductFromSummedReturnsAndGrossSales(): void
    {
        $result = $this->runner->run(
            'sales_items',
            ['return_rate'],
            [['code' => 'sku', 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );
        $rows = $this->bySku($result);

        // A: 10 / 50 = 0.2; B: 30 / 60 = 0.5
        $this->assertEqualsWithDelta(0.2, (float) $rows['ar-rf-a']['return_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.5, (float) $rows['ar-rf-b']['return_rate'], 0.0001);
    }

    public function testItAttributesRefundsToTheOrderDateRange(): void
    {
        // the credit memos were created today, but the refunds belong to the order date 2026-04-08
        $this->assertEqualsWithDelta(40.0, $this->runner->totals($this->orders('returns'))['returns'], 0.0001);

        $other = $this->runner->run('sales_orders', ['returns', 'orders'], [], [], '2026-04-09', '2026-04-30');
        $this->assertEquals(0, $this->runner->totals($other)['orders']);
        $this->assertEqualsWithDelta(0.0, (float) $this->runner->totals($other)['returns'], 0.0001);
    }

    private function orders(string $metric): ReportResultInterface
    {
        return $this->runner->run('sales_orders', [$metric], [], [], self::FROM, self::TO);
    }

    /**
     * Metric of sales_orders per order, keyed order1..order3 (chronological order of creation).
     *
     * @return array{order1: float, order2: float, order3: float}
     */
    private function metricByHour(string $metric): array
    {
        $result = $this->runner->run(
            'sales_orders',
            [$metric, 'orders'],
            [['code' => 'order_hour', 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );
        $byHour = [];
        foreach ($this->runner->rows($result) as $row) {
            // the hour dimension is zero-filled over 24 hours: keep the hours holding an order
            if ((int) $row['orders'] > 0) {
                $byHour[(int) $row['order_hour']] = (float) $row[$metric];
            }
        }
        ksort($byHour);
        // the hour of day is reported in the store timezone: map the three rows by chronological position
        $this->assertCount(3, $byHour);

        return array_combine(['order1', 'order2', 'order3'], array_values($byHour));
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
