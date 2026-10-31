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
use Magento\Sales\Test\Fixture\Creditmemo;
use Magento\Sales\Test\Fixture\Invoice;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Derived ratios are computed from aggregated components, zero denominators give null, empty periods give zero sums.
 *
 * Inputs (no tax, no discount, counted statuses '' = all but canceled, all orders created 2026-05-12 12:00 UTC;
 * the credit memo is created "now", outside the asserted range):
 *  - products: ar-dr-a (price 100, cost 60), ar-dr-b (price 10, cost 9), ar-dr-c (price 40, no cost)
 *  - order 1: 1 x A; order 2: 10 x B (invoiced, credit memo of 1 x B); order 3: 1 x C
 *
 * Expected values (hand-computed, hard-coded in the tests; item cost = unit cost x (ordered - canceled - refunded)):
 *  | sales_items     | A              | B                        | C                   | total                       |
 *  | gross_sales     | 100            | 100 = 10 * 10            | 40                  | 240                         |
 *  | returns         | 0              | 10 = 1 * 10              | 0                   | 10                          |
 *  | net_sales       | 100            | 90                       | 40                  | 230                         |
 *  | cost            | 60             | 81 = 9 * (10 - 1)        | 0 (no cost)         | 141                         |
 *  | gross_margin    | 40             | 9                        | 40                  | 89                          |
 *  | margin_pct      | 0.4 = 40 / 100 | 0.1 = 9 / 90             | 1.0 = 40 / 40       | 89 / 230 = 0.38696          |
 *  | return_rate     | 0              | 0.1 = 10 / 100           | 0                   | 10 / 240 = 0.04167          |
 *  | cost_coverage   | 1 = 1 / 1      | 1 = 1 / 1                | 0 = 0 / 1           | 2 / 3 = 0.66667             |
 *
 * The mean of the row margin percentages is (0.4 + 0.1 + 1.0) / 3 = 0.5 and the mean of the row return rates is
 * 0.1 / 3 = 0.0333: the totals row is computed from the summed components (89 / 230 and 10 / 240), not by
 * averaging row ratios.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
]
final class DerivedRatiosTest extends TestCase
{
    private const string FROM = '2026-05-12';
    private const string TO = '2026-05-12';

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
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        DataFixture(Product::class, ['sku' => 'ar-dr-a', 'price' => 100, 'cost' => 60, 'tax_class_id' => 0], 'productA'),
        DataFixture(Product::class, ['sku' => 'ar-dr-b', 'price' => 10, 'cost' => 9, 'tax_class_id' => 0], 'productB'),
        DataFixture(Product::class, ['sku' => 'ar-dr-c', 'price' => 40, 'tax_class_id' => 0], 'productC'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-dr-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productB.id$', 'qty' => 10]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-dr-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(Invoice::class, ['order_id' => '$order2.id$']),
        DataFixture(
            Creditmemo::class,
            [
                'order_id' => '$order2.id$',
                'items' => [['qty' => 1, 'product_id' => '$productB.id$']],
                'arguments' => ['shipping_amount' => 0],
            ]
        ),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart3'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$productC.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-dr-3@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-05-12 12:00:00']),
    ]
    public function testItComputesMarginPercentTotalsFromSummedMarginAndSummedNetSales(): void
    {
        $this->assertItemWithoutCostIsNull();

        $rows = $this->itemRowsBySku(['margin_pct']);
        $this->assertEqualsWithDelta(0.4, (float) $rows['ar-dr-a']['margin_pct'], 0.0001);
        $this->assertEqualsWithDelta(0.1, (float) $rows['ar-dr-b']['margin_pct'], 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $rows['ar-dr-c']['margin_pct'], 0.0001);

        $totals = $this->runner->totals($this->runner->run('sales_items', ['margin_pct'], [], [], self::FROM, self::TO));
        // 89 / 230, not the mean of the row percentages (0.5)
        $this->assertEqualsWithDelta(89 / 230, (float) $totals['margin_pct'], 0.0001);
        $this->assertNotEqualsWithDelta(0.5, (float) $totals['margin_pct'], 0.01);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        DataFixture(Product::class, ['sku' => 'ar-dr-a', 'price' => 100, 'cost' => 60, 'tax_class_id' => 0], 'productA'),
        DataFixture(Product::class, ['sku' => 'ar-dr-b', 'price' => 10, 'cost' => 9, 'tax_class_id' => 0], 'productB'),
        DataFixture(Product::class, ['sku' => 'ar-dr-c', 'price' => 40, 'tax_class_id' => 0], 'productC'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-dr-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productB.id$', 'qty' => 10]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-dr-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(Invoice::class, ['order_id' => '$order2.id$']),
        DataFixture(
            Creditmemo::class,
            [
                'order_id' => '$order2.id$',
                'items' => [['qty' => 1, 'product_id' => '$productB.id$']],
                'arguments' => ['shipping_amount' => 0],
            ]
        ),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart3'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$productC.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-dr-3@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-05-12 12:00:00']),
    ]
    public function testItComputesReturnRateTotalsFromSummedReturnsAndSummedGrossSales(): void
    {
        $rows = $this->itemRowsBySku(['return_rate']);
        $this->assertEqualsWithDelta(0.0, (float) $rows['ar-dr-a']['return_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.1, (float) $rows['ar-dr-b']['return_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $rows['ar-dr-c']['return_rate'], 0.0001);

        $totals = $this->runner->totals($this->runner->run('sales_items', ['return_rate'], [], [], self::FROM, self::TO));
        // 10 / 240, not the mean of the row rates (0.0333)
        $this->assertEqualsWithDelta(10 / 240, (float) $totals['return_rate'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        DataFixture(Product::class, ['sku' => 'ar-dr-a', 'price' => 100, 'cost' => 60, 'tax_class_id' => 0], 'productA'),
        DataFixture(Product::class, ['sku' => 'ar-dr-b', 'price' => 10, 'cost' => 9, 'tax_class_id' => 0], 'productB'),
        DataFixture(Product::class, ['sku' => 'ar-dr-c', 'price' => 40, 'tax_class_id' => 0], 'productC'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-dr-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productB.id$', 'qty' => 10]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-dr-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-05-12 12:00:00']),
        DataFixture(GuestCart::class, as: 'cart3'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$productC.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-dr-3@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
        DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-05-12 12:00:00']),
    ]
    public function testItComputesCostCoverageFromRowsWithCostOverAllRows(): void
    {
        $rows = $this->itemRowsBySku(['cost_coverage']);
        $this->assertEqualsWithDelta(1.0, (float) $rows['ar-dr-a']['cost_coverage'], 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $rows['ar-dr-b']['cost_coverage'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $rows['ar-dr-c']['cost_coverage'], 0.0001);

        $totals = $this->runner->totals($this->runner->run('sales_items', ['cost_coverage'], [], [], self::FROM, self::TO));
        $this->assertEqualsWithDelta(2 / 3, (float) $totals['cost_coverage'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReturnsNullAverageOrderValueForAPeriodWithoutOrders(): void
    {
        $result = $this->runner->run('sales_orders', ['orders', 'net_sales', 'aov'], [], [], self::FROM, self::TO);
        $totals = $this->runner->totals($result);

        $this->assertEquals(0, $totals['orders']);
        $this->assertEqualsWithDelta(0.0, (float) $totals['net_sales'], 0.0001);
        // 0 / 0: the zero denominator yields null, never 0 and never an error
        $this->assertNull($totals['aov']);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReturnsZeroCountsZeroSumsAndNoRowsForAnEmptyPeriodInEveryDataset(): void
    {
        $dimension = [['code' => 'customer_group_id', 'granularity' => null]];
        $cases = [
            'sales_orders' => [['orders', 'gross_sales', 'shipping', 'net_sales', 'aov'], ['orders'], ['gross_sales', 'shipping', 'net_sales'], ['aov']],
            'customer_orders' => [['orders', 'avg_spent', 'avg_shipping_cost', 'total_spent'], ['orders'], ['total_spent'], ['avg_spent', 'avg_shipping_cost']],
            'sales_items' => [['units_sold', 'gross_sales', 'returns', 'margin_pct', 'return_rate', 'cost_coverage'], [], ['units_sold', 'gross_sales', 'returns'], ['margin_pct', 'return_rate', 'cost_coverage']],
            'customers' => [['registrations'], ['registrations'], [], []],
        ];

        foreach ($cases as $dataset => [$metrics, $counts, $sums, $ratios]) {
            $result = $this->runner->run($dataset, $metrics, $dimension, [], self::FROM, self::TO);
            $this->assertSame([], $this->runner->rows($result), $dataset . ' rows');

            $totals = $this->runner->totals($result);
            foreach ($counts as $metric) {
                $this->assertEquals(0, $totals[$metric], $dataset . '.' . $metric);
            }
            // SUM metrics are COALESCEd to 0 (Review Decision), they are not null
            foreach ($sums as $metric) {
                $this->assertNotNull($totals[$metric], $dataset . '.' . $metric);
                $this->assertEqualsWithDelta(0.0, (float) $totals[$metric], 0.0001, $dataset . '.' . $metric);
            }
            foreach ($ratios as $metric) {
                $this->assertNull($totals[$metric], $dataset . '.' . $metric);
            }
        }
    }

    /**
     * ar-dr-c has no cost: the order item base_cost must be NULL (not 0) for cost_coverage to distinguish it.
     */
    private function assertItemWithoutCostIsNull(): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $costs = $connection->fetchPairs(
            $connection->select()
                ->from($resource->getTableName('sales_order_item'), ['sku', 'base_cost'])
                ->where('sku LIKE ?', 'ar-dr-%')
        );
        $this->assertArrayHasKey('ar-dr-c', $costs);
        $this->assertNull($costs['ar-dr-c']);
        $this->assertEqualsWithDelta(60.0, (float) $costs['ar-dr-a'], 0.0001);
    }

    /**
     * @param string[] $metrics
     * @return array<string,array<string,mixed>>
     */
    private function itemRowsBySku(array $metrics): array
    {
        return $this->bySku(
            $this->runner->run('sales_items', $metrics, [['code' => 'sku', 'granularity' => null]], [], self::FROM, self::TO)
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
