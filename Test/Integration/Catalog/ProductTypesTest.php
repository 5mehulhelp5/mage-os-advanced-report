<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Catalog;

use Magento\Bundle\Test\Fixture\AddProductToCart as AddBundleProductToCart;
use Magento\Bundle\Test\Fixture\Link as BundleSelection;
use Magento\Bundle\Test\Fixture\Option as BundleOption;
use Magento\Bundle\Test\Fixture\Product as BundleProduct;
use Magento\Catalog\Test\Fixture\Category;
use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\ConfigurableProduct\Test\Fixture\AddProductToCart as AddConfigurableProductToCart;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttribute;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProduct;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
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
 * Product-type scenario for the sales_items dataset (flat rate 5.00 per order, no tax, no discount,
 * every order created 2026-07-20 12:00:00 UTC, counted statuses = everything but canceled).
 *
 * Inputs:
 *  - configurable ar-pt-conf: children ar-pt-conf-red (price 30, cost 12), ar-pt-conf-blue (price 30, cost 15)
 *  - bundle ar-pt-bundle (dynamic price): ar-pt-b1 (10, cost 4) + ar-pt-b2 (20, cost 8), 1 each
 *  - simple ar-pt-s 5.00 in categories A and B
 *  - order 1: 1 x red + 2 x blue; order 2: 1 bundle; order 3: 2 x ar-pt-s
 *
 * Expected values (hard-coded in the tests):
 *  | order 1 lines          | red: 1 unit, gross 30, cost 12 | blue: 2 units, gross 60, cost 30 (2 * 15) |
 *  | order 1 configurable   | 3 units, gross 90, cost 42 (12 + 30), margin 48 (90 - 42)                  |
 *  | order 2 bundle         | parent line only: 1 unit, gross 30 (10 + 20)                               |
 *  | order 3                | 2 units, gross 10 (2 * 5)                                                  |
 *  | all items gross        | 130 = 90 + 30 + 10 = sales_orders gross_sales (sum of base_subtotal)       |
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
    DataFixture(Category::class, as: 'catA'),
    DataFixture(Category::class, as: 'catB'),
    DataFixture(ConfigurableAttribute::class, as: 'attr'),
    DataFixture(
        Product::class,
        ['sku' => 'ar-pt-conf-red', 'price' => 30, 'cost' => 12, 'tax_class_id' => 0],
        'red'
    ),
    DataFixture(
        Product::class,
        ['sku' => 'ar-pt-conf-blue', 'price' => 30, 'cost' => 15, 'tax_class_id' => 0],
        'blue'
    ),
    DataFixture(
        ConfigurableProduct::class,
        ['sku' => 'ar-pt-conf', '_options' => ['$attr$'], '_links' => ['$red$', '$blue$'], 'tax_class_id' => 0],
        'conf'
    ),
    DataFixture(Product::class, ['sku' => 'ar-pt-b1', 'price' => 10, 'cost' => 4, 'tax_class_id' => 0], 'b1'),
    DataFixture(Product::class, ['sku' => 'ar-pt-b2', 'price' => 20, 'cost' => 8, 'tax_class_id' => 0], 'b2'),
    DataFixture(BundleSelection::class, ['sku' => '$b1.sku$', 'qty' => 1], 'link1'),
    DataFixture(BundleSelection::class, ['sku' => '$b2.sku$', 'qty' => 1], 'link2'),
    DataFixture(BundleOption::class, ['product_links' => ['$link1$']], 'opt1'),
    DataFixture(BundleOption::class, ['product_links' => ['$link2$']], 'opt2'),
    DataFixture(
        BundleProduct::class,
        ['sku' => 'ar-pt-bundle', '_options' => ['$opt1$', '$opt2$'], 'tax_class_id' => 0],
        'bundle'
    ),
    DataFixture(
        Product::class,
        ['sku' => 'ar-pt-s', 'price' => 5, 'tax_class_id' => 0, 'category_ids' => ['$catA.id$', '$catB.id$']],
        'simple'
    ),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(
        AddConfigurableProductToCart::class,
        ['cart_id' => '$cart1.id$', 'product_id' => '$conf.id$', 'child_product_id' => '$red.id$', 'qty' => 1]
    ),
    DataFixture(
        AddConfigurableProductToCart::class,
        ['cart_id' => '$cart1.id$', 'product_id' => '$conf.id$', 'child_product_id' => '$blue.id$', 'qty' => 2]
    ),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-pt-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-07-20 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(
        AddBundleProductToCart::class,
        [
            'cart_id' => '$cart2.id$',
            'product_id' => '$bundle.id$',
            'selections' => [['$b1.id$'], ['$b2.id$']],
            'qty' => 1,
        ]
    ),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-pt-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-07-20 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$simple.id$', 'qty' => 2]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-pt-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-07-20 12:00:00']),
]
final class ProductTypesTest extends TestCase
{
    private const FROM = '2026-07-01';
    private const TO = '2026-07-31';

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

    public function testItReportsAConfigurableSaleOnceOnTheParentLine(): void
    {
        $rows = $this->rowsBy(
            $this->runItems(['units_sold', 'gross_sales'], 'parent_sku'),
            'parent_sku'
        );

        // red 1 + blue 2 = 3 units on a single parent line each, 30 + 2 * 30 = 90; children are not extra rows
        $this->assertEquals(3.0, (float) $rows['ar-pt-conf']['units_sold']);
        $this->assertEqualsWithDelta(90.0, (float) $rows['ar-pt-conf']['gross_sales'], 0.0001);
    }

    public function testItUsesTheChildProductCostForConfigurableMargin(): void
    {
        $rows = $this->rowsBy(
            $this->runItems(['units_sold', 'gross_sales', 'cost', 'gross_margin'], 'parent_sku'),
            'parent_sku'
        );
        $bySku = $this->rowsBy($this->runItems(['units_sold', 'cost'], 'sku'), 'sku');

        // cost 12 + 2 * 15 = 42; margin 90 - 42 = 48
        $this->assertEqualsWithDelta(42.0, (float) $rows['ar-pt-conf']['cost'], 0.0001);
        $this->assertEqualsWithDelta(48.0, (float) $rows['ar-pt-conf']['gross_margin'], 0.0001);
        // by SKU the configurable lines carry the child SKU: red 1 * 12, blue 2 * 15
        $this->assertEqualsWithDelta(12.0, (float) $bySku['ar-pt-conf-red']['cost'], 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $bySku['ar-pt-conf-blue']['cost'], 0.0001);
    }

    public function testItReportsABundleSaleOnTheParentLineOnly(): void
    {
        $rows = $this->rowsBy(
            $this->runItems(['units_sold', 'gross_sales', 'cost'], 'parent_sku'),
            'parent_sku'
        );

        // bundle parent line: 1 unit, 10 + 20 = 30; children ar-pt-b1/b2 are not reported
        $this->assertEquals(1.0, (float) $rows['ar-pt-bundle']['units_sold']);
        $this->assertEqualsWithDelta(30.0, (float) $rows['ar-pt-bundle']['gross_sales'], 0.0001);
        $this->assertArrayNotHasKey('ar-pt-b1', $rows);
        $this->assertArrayNotHasKey('ar-pt-b2', $rows);
        // documented limitation (R06): the bundle parent carries no cost
        $this->assertContains($rows['ar-pt-bundle']['cost'], [null, 0, 0.0, '0', '0.0000']);
    }

    public function testItReportsItemGrossSalesSummingToOrderGrossSalesForTheSameOrders(): void
    {
        $items = $this->runner->totals($this->runItems(['gross_sales']))['gross_sales'];
        $orders = $this->runner->totals(
            $this->runner->run('sales_orders', ['gross_sales'], [], [], self::FROM, self::TO)
        )['gross_sales'];

        // 90 + 30 + 10
        $this->assertEqualsWithDelta(130.0, (float) $items, 0.0001);
        $this->assertEqualsWithDelta(130.0, (float) $orders, 0.0001);
    }

    public function testItListsAMultiCategoryProductUnderEachCategoryWithANonAdditiveWarning(): void
    {
        $result = $this->runItems(['units_sold', 'gross_sales'], 'category_id');
        $rows = array_filter(
            $this->runner->rows($result),
            static fn (array $row): bool => $row['category_id'] !== null
        );

        // ar-pt-s (2 units, 10.00) is listed under both categories: 2 rows, each 2 units / 10.00
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertEquals(2.0, (float) $row['units_sold']);
            $this->assertEqualsWithDelta(10.0, (float) $row['gross_sales'], 0.0001);
        }
        $this->assertNotSame([], $result->getWarnings());
        $this->assertStringContainsString('not additive', implode(' ', $result->getWarnings()));
    }

    public function testItCountsDistinctOrdersPerProduct(): void
    {
        $rows = $this->rowsBy($this->runItems(['orders'], 'parent_sku'), 'parent_sku');

        // red and blue lines belong to the same order 1: one distinct order
        $this->assertEquals(1, (int) $rows['ar-pt-conf']['orders']);
        $this->assertEquals(1, (int) $rows['ar-pt-bundle']['orders']);
        $this->assertEquals(1, (int) $rows['ar-pt-s']['orders']);
    }

    /**
     * @param string[] $metrics
     */
    private function runItems(array $metrics, ?string $dimension = null): ReportResultInterface
    {
        return $this->runner->run(
            'sales_items',
            $metrics,
            $dimension === null ? [] : [['code' => $dimension, 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function rowsBy(ReportResultInterface $result, string $dimension): array
    {
        $map = [];
        foreach ($this->runner->rows($result) as $row) {
            $map[(string) $row[$dimension]] = $row;
        }

        return $map;
    }
}
