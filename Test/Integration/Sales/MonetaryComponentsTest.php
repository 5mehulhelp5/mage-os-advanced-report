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
use Magento\Quote\Test\Fixture\ApplyCoupon;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\SalesRule\Test\Fixture\Rule;
use Magento\Tax\Test\Fixture\TaxRate;
use Magento\Tax\Test\Fixture\TaxRule;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Monetary components of sales_orders on orders with a coupon discount and a 10% tax.
 *
 * Pinned configuration (on every test method, class-level Config is ignored by this Magento version):
 * flat rate price 5.00 (type O pinned; in this environment it is nevertheless charged per ordered unit, see below), checkmo, counted statuses '' (all but canceled), UTC timezone, tax:
 * prices exclude tax, tax computed on the subtotal AFTER discount (apply_after_discount=1), shipping price
 * excludes tax and has no tax class (shipping is not taxed). One TaxRate US/*, 10%, and one TaxRule for
 * product class 2 (Taxable Goods) and customer class 3 (Retail Customer, class of the guest group), matching the
 * US / region 1 / 36104 checkout address defaults. Rule AR-MC-10: 10% by_percent, specific coupon, all
 * websites and customer groups. All orders are guest orders created 2026-03-05 12:00 UTC.
 *
 * Products (tax class 2): ar-mc-a 20.00, ar-mc-b 50.00.
 *  - order 1: 1 x A + 1 x B, coupon AR-MC-10
 *  - order 2: 2 x A, no coupon
 *  - order 3: 1 x B, no coupon
 *
 * Hand-computed order values:
 *  | order | subtotal | discount | tax                | shipping | grand  |
 *  | 1     | 70.00    | 7.00     | 6.30 = (70-7)*10%  | 10.00    | 79.30  |
 *  | 2     | 40.00    | 0.00     | 4.00               | 10.00    | 54.00  |
 *  | 3     | 50.00    | 0.00     | 5.00               | 5.00     | 60.00  |
 * Shipping is 5.00 x ordered units (2, 2, 1 units) because the flat rate is applied per unit here even with
 * type O (class-level Config attributes are ignored and the fixtures place the orders before method config).
 *
 * Report values (3 orders, no refunds/cancellations/adjustments):
 *  gross_sales 160.00, discounts 7.00, taxes 15.30, shipping 25.00, returns 0,
 *  net_sales 153.00 = 160 - 7, total_sales 193.30 = 153 + 25 + 15.30, aov 51.00 = 153 / 3.
 *  By coupon_code: coupon => net 63.00 / 1 order (aov 63), no coupon => net 90.00 / 2 orders (aov 45);
 *  the average of the row aovs would be 54.00, not 51.00.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-mc-a', 'price' => 20, 'tax_class_id' => 2], 'productA'),
    DataFixture(Product::class, ['sku' => 'ar-mc-b', 'price' => 50, 'tax_class_id' => 2], 'productB'),
    DataFixture(
        TaxRate::class,
        ['tax_country_id' => 'US', 'tax_region_id' => 0, 'tax_postcode' => '*', 'rate' => 10],
        'rate'
    ),
    DataFixture(
        TaxRule::class,
        ['customer_tax_class_ids' => [3], 'product_tax_class_ids' => [2], 'tax_rate_ids' => ['$rate.id$']]
    ),
    DataFixture(
        Rule::class,
        [
            'simple_action' => 'by_percent',
            'discount_amount' => 10,
            'coupon_type' => 2,
            'coupon_code' => 'AR-MC-10',
        ]
    ),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-mc-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(ApplyCoupon::class, ['cart_id' => '$cart1.id$', 'coupon_codes' => ['AR-MC-10']]),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-03-05 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$productA.id$', 'qty' => 2]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-mc-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-03-05 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-mc-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-03-05 12:00:00']),
]
final class MonetaryComponentsTest extends TestCase
{
    private const FROM = '2026-03-01';
    private const TO = '2026-03-31';

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ReportTestRunner();
        $this->runner->login();
        $this->assertPlacedOrdersMatchHandComputedValues();
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
        Config('tax/calculation/price_includes_tax', '0'),
        Config('tax/calculation/shipping_includes_tax', '0'),
        Config('tax/calculation/apply_after_discount', '1'),
        Config('tax/classes/shipping_tax_class', '0'),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsGrossSalesAsTheSumOfOrderSubtotals(): void
    {
        $this->assertEqualsWithDelta(160.0, $this->totals(['gross_sales'])['gross_sales'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('tax/calculation/price_includes_tax', '0'),
        Config('tax/calculation/shipping_includes_tax', '0'),
        Config('tax/calculation/apply_after_discount', '1'),
        Config('tax/classes/shipping_tax_class', '0'),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsDiscountsAsPositiveAmountsFromTheCouponOrderOnly(): void
    {
        $this->assertEqualsWithDelta(7.0, $this->totals(['discounts'])['discounts'], 0.0001);

        $byCoupon = $this->byCoupon(['discounts']);
        $this->assertEqualsWithDelta(7.0, (float) $byCoupon['AR-MC-10']['discounts'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $byCoupon['']['discounts'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('tax/calculation/price_includes_tax', '0'),
        Config('tax/calculation/shipping_includes_tax', '0'),
        Config('tax/calculation/apply_after_discount', '1'),
        Config('tax/classes/shipping_tax_class', '0'),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsTaxesNetOfNothingWhenNoRefundOrCancellationExists(): void
    {
        // 6.30 + 4.00 + 5.00
        $this->assertEqualsWithDelta(15.3, $this->totals(['taxes'])['taxes'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('tax/calculation/price_includes_tax', '0'),
        Config('tax/calculation/shipping_includes_tax', '0'),
        Config('tax/calculation/apply_after_discount', '1'),
        Config('tax/classes/shipping_tax_class', '0'),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsShippingAsFlatRatePerOrder(): void
    {
        // 5.00 x (2 + 2 + 1) ordered units
        $this->assertEqualsWithDelta(25.0, $this->totals(['shipping'])['shipping'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('tax/calculation/price_includes_tax', '0'),
        Config('tax/calculation/shipping_includes_tax', '0'),
        Config('tax/calculation/apply_after_discount', '1'),
        Config('tax/classes/shipping_tax_class', '0'),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItComputesNetSalesAsGrossMinusDiscountsMinusReturns(): void
    {
        $totals = $this->totals(['gross_sales', 'discounts', 'returns', 'net_sales']);

        $this->assertEqualsWithDelta(0.0, (float) $totals['returns'], 0.0001);
        // 160 - 7 - 0
        $this->assertEqualsWithDelta(153.0, $totals['net_sales'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('tax/calculation/price_includes_tax', '0'),
        Config('tax/calculation/shipping_includes_tax', '0'),
        Config('tax/calculation/apply_after_discount', '1'),
        Config('tax/classes/shipping_tax_class', '0'),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItComputesTotalSalesAsNetPlusShippingPlusTaxesMinusAdjustments(): void
    {
        $totals = $this->totals(['net_sales', 'shipping', 'taxes', 'adjustments', 'total_sales']);

        // 153 + 25 + 15.30 - 0
        $this->assertEqualsWithDelta(193.3, $totals['total_sales'], 0.0001);
    }

    #[
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('tax/calculation/price_includes_tax', '0'),
        Config('tax/calculation/shipping_includes_tax', '0'),
        Config('tax/calculation/apply_after_discount', '1'),
        Config('tax/classes/shipping_tax_class', '0'),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItComputesAverageOrderValueAsTotalNetSalesDividedByOrderCountNotAsAverageOfRowValues(): void
    {
        // 153 / 3
        $total = $this->totals(['aov'])['aov'];
        $this->assertEqualsWithDelta(51.0, $total, 0.0001);

        $byCoupon = $this->byCoupon(['aov']);
        $this->assertEqualsWithDelta(63.0, $byCoupon['AR-MC-10']['aov'], 0.0001);
        $this->assertEqualsWithDelta(45.0, $byCoupon['']['aov'], 0.0001);
        // the plain average of the row values (54.00) must not be reported as total
        $this->assertNotEqualsWithDelta(54.0, $total, 0.0001);
    }

    /**
     * @param string[] $metrics
     * @return array<string,mixed>
     */
    private function totals(array $metrics): array
    {
        return $this->runner->totals($this->runner->run('sales_orders', $metrics, [], [], self::FROM, self::TO));
    }

    /**
     * @param string[] $metrics
     * @return array<string,array<string,mixed>> rows keyed by coupon code ('' = no coupon)
     */
    private function byCoupon(array $metrics): array
    {
        $result = $this->runner->run(
            'sales_orders',
            $metrics,
            [['code' => 'coupon_code', 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );

        $map = [];
        foreach ($this->runner->rows($result) as $row) {
            $map[(string) ($row['coupon_code'] ?? '')] = $row;
        }

        return $map;
    }

    private function assertPlacedOrdersMatchHandComputedValues(): void
    {
        // read from the fixture storage: loading orders through the repository caches them and breaks the
        // fixture revert (cancel re-inserts tax items of rolled-back rows)
        // [subtotal, discount (negative on the order), tax, shipping, grand total]
        $expected = [
            'order1' => [70.0, -7.0, 6.3, 10.0, 79.3],
            'order2' => [40.0, 0.0, 4.0, 10.0, 54.0],
            'order3' => [50.0, 0.0, 5.0, 5.0, 60.0],
        ];
        $objectManager = Bootstrap::getObjectManager();
        $storage = $objectManager->get(DataFixtureStorageManager::class)->getStorage();
        foreach ($expected as $key => $values) {
            $order = $storage->get($key);
            $actual = [
                (float) $order->getBaseSubtotal(),
                (float) $order->getBaseDiscountAmount(),
                (float) $order->getBaseTaxAmount(),
                (float) $order->getBaseShippingAmount(),
                (float) $order->getBaseGrandTotal(),
            ];
            foreach ($values as $i => $value) {
                $this->assertEqualsWithDelta($value, $actual[$i], 0.0001, $key . ' component #' . $i);
            }
        }
    }
}
