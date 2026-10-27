<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Preset;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\InventoryApi\Test\Fixture\SourceItems;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Test\Integration\Fixture\CancelOrder;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Virtual metrics of the `low_stock_coverage` and `dead_stock` presets. Every method declares its own SKU
 * (`ar-pm-<n>-a`, price 10) and orders, because the nested `sales_items` run of `low_stock_coverage` is cached.
 *
 * Order dates are relative to now and set in the test body ({@see setAges()}): `created_at` is 12:00 UTC of the day
 * N days ago, so the UTC calendar-day difference is exactly N and no order is close to a window boundary.
 * `low_stock_coverage` counts sales in `last_30_days` (store-local today - 29 .. today); `dead_stock` pins
 * `dead_stock_days` to 2 per method (default is 90), so sales 5 and more days old leave the SKU listed.
 * Quantity on hand is the default-source quantity set by a fixture (physical stock, not salable).
 *
 * Expected values (rows filtered to the SKU, preset totals are not asserted):
 *  | method | preset             | inputs                                   | expected                          |
 *  | 1      | low_stock_coverage | qty 50; 3, 2, 4 units 5, 20, 40 days ago | units_sold_30d = 3 + 2 = 5        |
 *  | 2      | low_stock_coverage | qty 50; 9 units 5 days ago               | avg_daily_sales = 9 / 30 = 0.3    |
 *  | 3      | low_stock_coverage | qty 12; 6 units 5 days ago               | coverage_days = 12 / 0.2 = 60     |
 *  | 4      | low_stock_coverage | qty 7; no orders                         | units 0, avg 0, coverage null     |
 *  | 5      | dead_stock         | 1 unit 5 and 40 days ago                 | days_since_last_sale = 5          |
 *  | 6      | dead_stock         | 1 unit 40 days ago; canceled 10 days ago | days_since_last_sale = 40         |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('carriers/flatrate/active', '1'),
    Config('carriers/flatrate/type', 'O'),
    Config('carriers/flatrate/price', '5'),
    Config('payment/checkmo/active', '1'),
]
final class PresetMetricsTest extends TestCase
{
    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        Bootstrap::getObjectManager()->get(CacheType::class)->clean();
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    /**
     * Orders of 3, 2 and 4 units placed 5, 20 and 40 days ago.
     */
    #[
        DataFixture(Product::class, ['sku' => 'ar-pm-1-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-pm-1-a-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 2]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-pm-1-a-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(GuestCart::class, as: 'cart3'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 4]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-pm-1-a-3@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
        DataFixture(SourceItems::class, [['sku' => 'ar-pm-1-a', 'source_code' => 'default', 'quantity' => 50, 'status' => 1]]),
    ]
    public function testItSumsUnitsSoldInTheLast30DaysAndIgnoresOlderOrders(): void
    {
        $this->setAges([5, 20, 40]);

        $row = $this->presetRow('low_stock_coverage', 'ar-pm-1-a');

        // 3 + 2; the 40 days old order is outside last_30_days
        $this->assertEqualsWithDelta(5.0, (float) $row['units_sold_30d'], 0.0001);
    }

    /**
     * One order of 9 units placed 5 days ago.
     */
    #[
        DataFixture(Product::class, ['sku' => 'ar-pm-2-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 9]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-pm-2-a-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(SourceItems::class, [['sku' => 'ar-pm-2-a', 'source_code' => 'default', 'quantity' => 50, 'status' => 1]]),
    ]
    public function testItComputesAverageDailySalesAsUnitsSoldIn30DaysDividedBy30(): void
    {
        $this->setAges([5]);

        $row = $this->presetRow('low_stock_coverage', 'ar-pm-2-a');

        // 9 / 30
        $this->assertEqualsWithDelta(0.3, (float) $row['avg_daily_sales'], 0.0001);
    }

    /**
     * Quantity on hand 12, one order of 6 units placed 5 days ago.
     */
    #[
        DataFixture(Product::class, ['sku' => 'ar-pm-3-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 6]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-pm-3-a-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(SourceItems::class, [['sku' => 'ar-pm-3-a', 'source_code' => 'default', 'quantity' => 12, 'status' => 1]]),
    ]
    public function testItComputesCoverageDaysAsQuantityOnHandDividedByAverageDailySales(): void
    {
        $this->setAges([5]);

        $row = $this->presetRow('low_stock_coverage', 'ar-pm-3-a');

        // 12 / (6 / 30)
        $this->assertEqualsWithDelta(60.0, (float) $row['coverage_days'], 0.0001);
    }

    /**
     * Quantity on hand 7, no orders.
     */
    #[
        DataFixture(Product::class, ['sku' => 'ar-pm-4-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
        DataFixture(SourceItems::class, [['sku' => 'ar-pm-4-a', 'source_code' => 'default', 'quantity' => 7, 'status' => 1]]),
    ]
    public function testItReturnsZeroSalesAndNullCoverageDaysForASkuWithoutSales(): void
    {
        $row = $this->presetRow('low_stock_coverage', 'ar-pm-4-a');

        $this->assertEqualsWithDelta(0.0, (float) $row['units_sold_30d'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $row['avg_daily_sales'], 0.0001);
        $this->assertNull($row['coverage_days']);
    }

    /**
     * Orders of 1 unit placed 5 and 40 days ago; dead_stock_days pinned to 2.
     */
    #[
        Config('mageos_advancedreport/catalog/dead_stock_days', '2'),
        DataFixture(Product::class, ['sku' => 'ar-pm-5-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-pm-5-a-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-pm-5-a-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    ]
    public function testItComputesDaysSinceLastSaleFromTheMostRecentCountedOrder(): void
    {
        $this->setAges([5, 40]);

        $row = $this->presetRow('dead_stock', 'ar-pm-5-a');

        // the most recent sale is 5 days old (UTC calendar days)
        $this->assertEqualsWithDelta(5.0, (float) $row['days_since_last_sale'], 0.0001);
    }

    /**
     * A counted order placed 40 days ago and a canceled one placed 10 days ago; dead_stock_days pinned to 2.
     */
    #[
        Config('mageos_advancedreport/catalog/dead_stock_days', '2'),
        DataFixture(Product::class, ['sku' => 'ar-pm-6-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-pm-6-a-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-pm-6-a-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(CancelOrder::class, ['order_id' => '$order2.id$']),
    ]
    public function testItIgnoresCanceledOrdersWhenComputingDaysSinceLastSale(): void
    {
        $this->setAges([40, 10]);

        $row = $this->presetRow('dead_stock', 'ar-pm-6-a');

        // the canceled order of 10 days ago does not count
        $this->assertEqualsWithDelta(40.0, (float) $row['days_since_last_sale'], 0.0001);
    }

    /**
     * Moves order N (fixture `orderN`) to 12:00 UTC of the day `$ages[N - 1]` days ago.
     *
     * @param int[] $ages
     */
    private function setAges(array $ages): void
    {
        $setter = Bootstrap::getObjectManager()->get(SetOrderCreatedAt::class);
        foreach ($ages as $index => $days) {
            $order = DataFixtureStorageManager::getStorage()->get('order' . ($index + 1));
            $setter->apply([
                'order_id' => (int) $order->getId(),
                'created_at' => gmdate('Y-m-d 12:00:00', time() - $days * 86400),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function presetRow(string $preset, string $sku): array
    {
        $result = $this->runner->runPreset($preset, gmdate('Y-m-d', time() - 86400 * 60), gmdate('Y-m-d'));
        foreach ($this->runner->rows($result) as $row) {
            if ($row['sku'] === $sku) {
                return $row;
            }
        }

        $this->fail(\sprintf('SKU %s not listed by preset %s.', $sku, $preset));
    }
}
